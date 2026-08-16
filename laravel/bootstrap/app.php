<?php

use App\Exceptions\ErrorPageResponder;
use App\Exceptions\StaleResourceRedirector;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // マスタ管理など管理者専用ルートで使用するエイリアス
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 削除済みリソースへの操作（stale ページからの削除・編集遷移、編集中の並行削除）で出る 404 を、
        // 生のエラー画面ではなく「一覧へ戻す＋フラッシュ」に差し替える（issue #44）。
        //
        // 判定と戻り先の対応表は StaleResourceRedirector に集約し、ここは委譲だけに留める。
        // null が返った場合は Laravel 既定の 404 応答にフォールバックする
        // （未定義 URL・意図的な abort(404)・非 Inertia リクエストはこちら）。
        // そのフォールバックを下の respond() が受け取り、共通エラーページに差し替える（issue #70）。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return app(StaleResourceRedirector::class)->handle($e, $request);
        });

        // 上の render() で差し替えられなかったエラー応答（403 / 404 / 419 / 500 / 503）を、
        // アプリの体裁を保った案内ページ・再ログイン導線に差し替える（issue #70）。
        //
        // respond() は Handler::finalizeRenderedResponse として **すべての例外応答の最終段** で呼ばれる。
        // 例外クラス単位の render() を複数積むより取りこぼしが起きにくい一方、上の render() が返した
        // リダイレクトやバリデーションの 422 もここを通るため、対象は ErrorPageResponder 側の
        // 許可リストで厳密に絞る。ここは委譲だけに留める。
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            return app(ErrorPageResponder::class)->respond($response, $request);
        });
    })->create();
