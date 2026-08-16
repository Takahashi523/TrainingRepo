<?php

namespace App\Exceptions;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * フレームワーク既定のエラー応答を、アプリの体裁を保った案内ページに差し替える（issue #70）。
 *
 * #44（StaleResourceRedirector）が「エラーを出さないようにする」対策なのに対し、
 * 本クラスは「それでも出てしまったときの受け皿」を担う。
 *
 * 入口は 2 つあり、どちらも最終的に page() に集約する。
 *  [A] bootstrap/app.php の $exceptions->respond() … ルート内で発生した例外（403 / 404 / 419 / 500 / 503）
 *  [B] routes/web.php の Route::fallback()          … 未定義 URL（ルートに一致しないアクセス）
 *
 * [B] が必要な理由：未定義 URL はルート解決の時点で例外になるため web ミドルウェアグループ
 * （StartSession / HandleInertiaRequests）が実行されない。そのまま [A] で描画すると、
 * ログイン済みでもセッションが無く auth.user が null になり、ゲスト用の画面を見せてしまう。
 * fallback ルートならルート解決に成功するため、共有 Props が揃った状態で描画できる。
 */
final class ErrorPageResponder
{
    /**
     * 案内ページに差し替える対象のステータス。
     *
     * 許可リスト方式にしている。respond() は 4xx / 5xx だけでなく **すべての例外応答** が通るため、
     * 「400 以上なら差し替える」といった範囲判定にすると、バリデーションの 422（Inertia の onError が
     * 依存している）や #44 のリダイレクト（302 / 303）まで巻き込み、画面がサイレントに差し替わる。
     *
     * 405 を含めているのは、Route::fallback() が GET / HEAD にしか登録されないため
     * （Illuminate\Routing\Router::fallback）。未定義 URL への POST / DELETE は
     * 「URI は fallback に一致するがメソッドが違う」と判定され 404 ではなく 405 になる。
     * ここで受けないと、古いタブからの送信が素のエラー画面に落ちる。
     *
     * 429（レート制限）は現時点で throttle ミドルウェアを使っておらず到達経路が無いため、
     * 意図的に含めていない。throttle を導入する際はここに追加すること。
     */
    private const HANDLED_STATUSES = [403, 404, 405, 419, 500, 503];

    /** URL 自体が存在しない（打ち間違い・古いブックマーク） */
    private const REASON_MISSING_PAGE = 'missing_page';

    /** URL は正しいが対象レコードが無い（削除済み・ID 違い） */
    private const REASON_MISSING_RESOURCE = 'missing_resource';

    /**
     * [A] 例外ハンドラの最終段。差し替え対象でなければ元の応答をそのまま返す。
     */
    public function respond(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        // 対象外のステータス（200 系・#44 の 302/303・バリデーションの 422・認証の 302 など）は素通し。
        if (! in_array($status, self::HANDLED_STATUSES, true)) {
            return $response;
        }

        // API・JSON クライアントには HTML の案内ページを返さない。
        // Inertia のリクエストは Accept が text/html 系のため expectsJson() は false であり、
        // ここには掛からない（掛かるのは本物の JSON クライアントだけ）。
        if ($request->expectsJson()) {
            return $response;
        }

        // 419（CSRF トークン失効）は案内ページを出さず、認証状態に応じた復帰導線へ送る。
        if ($status === 419) {
            return $this->tokenExpired($request);
        }

        // 開発時（APP_DEBUG=true）の 500 は Ignition のスタックトレースを残す。
        // 不具合調査の主要な手段であり、これを潰すと調査コストが上がるため。
        // 404 / 403 は Ignition に有用な情報が無く、本番と同じ見え方を開発中に確認できる利点が勝るので差し替える。
        if ($status === 500 && config('app.debug')) {
            return $response;
        }

        // ここに来る 404 は「URL は正しいがレコードが無い」（ルートモデルバインディング失敗・
        // MatchingController の意図的な abort(404)）。未定義 URL は [B] が先に処理する。
        //
        // 案内ページの描画自体が失敗した場合（Vite マニフェスト欠落など）は元の応答へ退避する。
        // 「エラーページが出せない」ことを二次障害（空の 500）に育てないためのフェイルセーフ。
        try {
            return $this->page($request, $status, $status === 404 ? self::REASON_MISSING_RESOURCE : null);
        } catch (Throwable) {
            return $response;
        }
    }

    /**
     * [B] 未定義 URL。web ミドルウェアを通った状態で 404 の案内ページを返す。
     */
    public function fallback(Request $request): Response
    {
        // JSON クライアントには従来どおりの 404 を返す（例外を投げ直し、[A] の expectsJson ガードに委ねる）。
        if ($request->expectsJson()) {
            throw new NotFoundHttpException;
        }

        return $this->page($request, 404, self::REASON_MISSING_PAGE);
    }

    /**
     * 共通のエラーページ応答。
     *
     * ステータスコードは元の値を維持する（404 は 404 のまま返す）。差し替えるのは中身だけであり、
     * 応答の意味を変えると既存のテスト・クライアントの判断を狂わせるため。
     */
    private function page(Request $request, int $status, ?string $reason): Response
    {
        $this->ensureSharedPropsAreAvailable($request);

        return Inertia::render('Error', [
            'status' => $status,
            'reason' => $reason,
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * 共有 Props（auth / flash）が未設定なら、ここで設定する。
     *
     * HandleInertiaRequests は web グループの **末尾** に append されているため、
     * ルートモデルバインディング（SubstituteBindings）で 404 になるケースでは一度も実行されない。
     * その状態で描画すると auth Props が存在せず、**ログイン済みでも未認証向けの表示**
     * （サイドバー無し・「ログイン画面へ」）になってしまう。
     *
     * ミドルウェアの並び順そのものを変える方法もあるが、全リクエストに影響するため採らない。
     * 共有内容は HandleInertiaRequests::share() を再利用し、定義を二重に持たない。
     */
    private function ensureSharedPropsAreAvailable(Request $request): void
    {
        // セッションが開始されていない経路（メンテナンス 503 など、web グループより前で
        // 発生する例外）では認証状態を解決できないため、共有を試みない。
        if (! $request->hasSession() || Inertia::getShared('auth') !== null) {
            return;
        }

        $middleware = app(HandleInertiaRequests::class);

        // バージョンも同じミドルウェアが設定するため、ここで併せて補う。
        // 欠けると version が空文字のまま描画され、このページからの次の Inertia 遷移が
        // バージョン不一致（409）と判定されて毎回フルリロードになる。
        Inertia::version(fn () => $middleware->version($request));
        Inertia::share($middleware->share($request));
    }

    /**
     * CSRF トークン不一致（419）。認証状態で復帰先を変える。
     *
     * Laravel 13 の PreventRequestForgery は Sec-Fetch-Site: same-origin をトークン検証より先に
     * 通すため、**同一オリジンのブラウザ操作では 419 は発生しない**（画面操作でのセッション期限切れは
     * auth ミドルウェアの 302 →ログインになる）。ここに到達するのはクロスサイト送信や
     * Sec-Fetch-Site を送らないクライアント（curl・古いブラウザ）であり、その防御として維持する。
     *
     * ログイン済み（セッションは生きていてトークンだけ失効した状態）でログイン画面へ送ると、
     * guest ミドルウェアがログイン済みを弾いてダッシュボードへ再リダイレクトし、
     * フラッシュが一度も描画されずに消える＝送信が失敗したことすらユーザーに伝わらない
     * （Silent Rejection）。そのため元の画面へ戻し、既存の flash → トースト基盤で理由を伝える。
     *
     * 未ログイン（セッションごと切れている）では再ログインが必要なため、ログイン画面へ送り、
     * 既存の Auth/Login の status Props（AuthenticatedSessionController@create が
     * session('status') を渡す）に相乗りする。いずれも通知経路は増やさない。
     */
    private function tokenExpired(Request $request): RedirectResponse
    {
        $redirect = $this->isAuthenticated($request)
            ? redirect()->to($this->previousUrlWithinApp($request))
                ->with('error', 'ページを開いてから時間が経過したため、送信できませんでした。お手数ですが、もう一度操作してください。')
            : redirect()->route('login')
                ->with('status', 'セッションの有効期限が切れました。もう一度ログインしてください。');

        // Inertia は PUT / PATCH / DELETE への 302 を追えないため 303 See Other で返す（#44 と同じ理由）。
        if (! $request->isMethodSafe()) {
            $redirect->setStatusCode(303);
        }

        return $redirect;
    }

    /**
     * セッションが無い経路（web グループより前で発生した例外）で $request->user() を呼ぶと
     * セッションストア未設定の例外になるため、先に存在を確認する。
     */
    private function isAuthenticated(Request $request): bool
    {
        return $request->hasSession() && $request->user() !== null;
    }

    /**
     * 戻り先の URL。**自ホスト内に限定する。**
     *
     * url()->previous() は Referer ヘッダーを最優先で使い（UrlGenerator::previous）、絶対 URL は
     * そのまま返される。419 はクロスサイトからの送信でも発生しうるため、そのまま使うと
     * 攻撃者が指定した外部 URL へのリダイレクトを自ドメインから発行することになる
     * （CSRF 自体はミドルウェアが既に阻止しており実害は小さいが、オープンリダイレクトの原型を残さない）。
     */
    private function previousUrlWithinApp(Request $request): string
    {
        $fallback = route('dashboard');
        $previous = url()->previous(fallback: $fallback);
        $host = parse_url($previous, PHP_URL_HOST);

        // ホスト指定なし（相対 URL）か、自ホストと一致する場合のみ採用する。
        return $host === null || $host === $request->getHost() ? $previous : $fallback;
    }
}
