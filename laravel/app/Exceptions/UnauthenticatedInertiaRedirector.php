<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 未ログイン状態でのInertia非GETリクエスト（DELETE/PUT/PATCH）が、ログイン画面への
 * 302リダイレクトを経由して405（MethodNotAllowedHttpException）になる問題への対応（issue #63）。
 *
 * auth ミドルウェアが投げる AuthenticationException は、HandleInertiaRequests
 * （Inertia\Middleware）の後処理より内側で発生するため、Laravel標準の unauthenticated()
 * が返す302はInertiaの「非GETへの302を303に書き換える」処理を通らない。302は
 * DELETE/PUT/PATCHのメソッドを保持したままブラウザに追わせてしまう（303と違い、
 * ダウングレードされるのはPOSTだけ）ため、login ルート（GET/HEAD/POSTのみ）へ元の
 * メソッドのままリクエストされ、405になる。
 *
 * 元の画面へ戻すのではなくログイン画面への強制遷移が必要なため、303への書き換え
 * （Inertia内で完結する遷移）ではなく、Inertia公式の「external redirect」の仕組み
 * （409 + X-Inertia-Location によるフルページ遷移）を使う。単純な303リダイレクトでも
 * 遷移自体はできるが、セッション切れ後はCookie・CSRFトークン・Inertiaのバージョン情報
 * などクライアント側の状態が古くなっている可能性があるため、素のブラウザ遷移で
 * まっさらな状態から読み込ませる方が安全。
 *
 * なお、CSRFトークン不一致（419）への対応は issue #70（共通エラーページ、PR #77）の
 * ErrorPageResponder に一本化しており、本クラスの対象外（当初はissue #63の②として
 * 本クラスと対で実装していたが、対応範囲の重複を避けるため本PRからは削除した）。
 */
final class UnauthenticatedInertiaRedirector
{
    /**
     * Inertiaリクエストであれば、ログイン画面へのフルページ遷移応答（409 +
     * X-Inertia-Location）を返す。クライアント側はこれを受けて window.location で
     * 通常のGETナビゲーションとしてログイン画面へ遷移するため、DELETE等のメソッドを
     * 引き継いでしまう問題自体が起きなくなる。
     *
     * 非Inertia（APIコール・テストからの直接リクエスト等）はLaravel標準の挙動
     * （JSONを期待するなら401、それ以外は302リダイレクト）に委ねたいので null を返す。
     */
    public function handle(AuthenticationException $e, Request $request): ?Response
    {
        if (! $request->inertia()) {
            return null;
        }

        $this->rememberIntendedUrl($request);

        // $e->redirectTo() は、ガードごとに個別のリダイレクト先を登録できる仕組み
        // （AuthenticationException::redirectUsing()）。現状このアプリはログイン画面が
        // 1つだけなので常に null になり route('login') にフォールバックするが、Laravel
        // 標準の unauthenticated() と同じ書き方に揃えておくことで、将来ガードが増えても
        // 個別リダイレクト先の登録だけで対応できるようにしている。
        return Inertia::location($e->redirectTo($request) ?? route('login'));
    }

    /**
     * ログイン後に元の画面へ戻せるよう、intended URL をセッションへ保存する。
     *
     * Laravel標準の unauthenticated() は redirect()->guest() を経由し、その中で
     * intended URL（GETならリクエストURL自体、非GETなら1つ前の画面のURL）を
     * session('url.intended') に保存する。AuthenticatedSessionController::store() の
     * redirect()->intended(route('dashboard')) はこれを読みに行くため、保存されていないと
     * 再ログイン後は常にダッシュボードへ飛んでしまう。
     *
     * 本クラスは guest() を経由せず Inertia::location() を直接返すため、この保存だけ
     * 明示的に行う必要がある。判定式は Illuminate\Routing\Redirector::guest() と同じ
     * （GET・ルート確定済み・JSONを期待しない場合のみ現在のURLを使い、それ以外は
     * 1つ前の画面のURLを使う）。
     */
    private function rememberIntendedUrl(Request $request): void
    {
        $intended = $request->isMethod('GET') && $request->route() && ! $request->expectsJson()
            ? $request->fullUrl()
            : url()->previous();

        if ($intended) {
            $request->session()->put('url.intended', $intended);
        }
    }
}
