<?php

namespace App\Exceptions;

use App\Models\Engineer;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 削除済みリソースに対する操作で発生した 404 を「一覧へ戻す＋フラッシュ」に差し替える（issue #44）。
 *
 * 対象は次のような、ユーザーに落ち度がない 404。
 *  - ブラウザバックで表示された古い（stale）詳細ページからの削除・編集遷移
 *  - 編集画面を開いている間に別ユーザーがそのレコードを削除した場合の保存（並行削除）
 *
 * 404 を返すこと自体は正しい挙動なので、レスポンスの意味は変えない。
 * 変えるのは「Inertia で操作したときの見せ方」だけであり、それ以外（API・テスト・未定義 URL）は
 * 従来どおり 404 のままとする。
 */
final class StaleResourceRedirector
{
    /**
     * リダイレクト対象とする「ルートモデルバインディングのモデル」→「戻り先・画面上の呼称」。
     *
     * ホワイトリスト方式にしている。モデルが今後追加されたときに、意図しないものまで
     * 自動でリダイレクト対象になる（＝404 が握りつぶされる）ことを防ぐため。
     *
     * route が null のリソースは専用の一覧画面を持たないため、呼び出し元へ戻す。
     * admin_only の戻り先は管理者しか開けないため、管理者以外には差し替えを行わない（下記参照）。
     *
     * @var array<class-string, array{route: string|null, label: string, admin_only?: bool}>
     */
    private const RESOURCE_MAP = [
        Project::class => ['route' => 'projects.index', 'label' => '案件'],
        Engineer::class => ['route' => 'engineers.index', 'label' => '人材'],
        // 画面名は「進捗管理」だが、レコード 1 件の呼称は既存フラッシュ（PipelineController の
        // 「パイプラインを削除しました。」等）に合わせて「パイプライン」で統一する。
        Pipeline::class => ['route' => 'pipelines.index', 'label' => 'パイプライン'],
        User::class => ['route' => 'master.index', 'label' => 'ユーザー', 'admin_only' => true],
        // 保存済み条件は人材・案件一覧の中から操作されるため、専用の一覧を持たない。
        // 呼称は既存フラッシュ・モーダル文言（「検索条件を削除しました。」等）に合わせる
        // （「保存済み条件」は一覧セクションの見出しであり、レコード 1 件の呼称ではない）。
        SavedSearch::class => ['route' => null, 'label' => '検索条件'],
    ];

    /**
     * stale な 404 であればリダイレクト応答を返す。該当しなければ null を返し、
     * 呼び出し元（bootstrap/app.php）で Laravel 既定の 404 応答にフォールバックさせる。
     */
    public function handle(NotFoundHttpException $e, Request $request): ?RedirectResponse
    {
        // 非 Inertia（API 呼び出し・CSV ダウンロード・テストの直接リクエスト等）は
        // 404 のまま返す。画面遷移を伴わない相手にリダイレクトを返しても意味がないため。
        if (! $request->inertia()) {
            return null;
        }

        // ルートモデルバインディングの失敗は、Laravel の例外ハンドラで
        // ModelNotFoundException → NotFoundHttpException に変換され、元例外が previous に入る。
        // ここが null の 404 は「未定義 URL」や意図的な abort(404) なので対象外とする。
        $previous = $e->getPrevious();
        if (! $previous instanceof ModelNotFoundException) {
            return null;
        }

        $resource = self::RESOURCE_MAP[$previous->getModel()] ?? null;
        if ($resource === null) {
            return null;
        }

        // 戻り先が管理者専用画面の場合、管理者以外はそこを開けない（admin ミドルウェアで 403）。
        // ルートモデルバインディング（web グループ）は admin ミドルウェアより先に走るため、
        // 一般ユーザーがマスタ管理の URL を直接叩くと、認可判定の前にこの 404 が発生する。
        // ここで差し替えると「403 になる画面へリダイレクト → そこで改めてエラー」となり
        // かえって分かりにくいため、権限のないユーザーには従来どおりの応答を返す。
        if (($resource['admin_only'] ?? false) && $request->user()?->role !== 'admin') {
            return null;
        }

        $redirect = $resource['route'] !== null
            ? redirect()->route($resource['route'])
            : redirect()->back(fallback: route('dashboard'));

        // 表示は既存の flash → トースト基盤（HandleInertiaRequests の flash.error →
        // AuthenticatedLayout の useToast）に相乗りする。通知経路を増やさない。
        //
        // 「削除された」と断定しない。この経路は stale ページからの操作だけでなく、最初から存在しない
        // ID（打ち間違い・古い共有 URL）でも通る。ハード削除のため「かつて存在したか」はサーバーからも
        // 判別できない。事実（見つからない）を述べ、原因は可能性として添える
        // （共通エラーページ #70 の「対象が見つかりません」と同じ語彙）。
        $redirect->with('error', "対象の{$resource['label']}が見つかりません。既に削除された可能性があります。");

        // Inertia は PUT / PATCH / DELETE への 302 を追えないため 303 See Other で返す
        // （302 だとリダイレクト先へ元のメソッドが引き継がれる）。
        // Inertia のミドルウェアにも同等の変換があるが、例外ハンドラで生成した応答が
        // そこを通る保証はないため、ここで明示的に設定する。
        if (! $request->isMethodSafe()) {
            $redirect->setStatusCode(303);
        }

        return $redirect;
    }
}
