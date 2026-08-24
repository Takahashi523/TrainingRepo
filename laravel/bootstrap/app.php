<?php

use App\Exceptions\StaleResourceRedirector;
use App\Exceptions\UnauthenticatedInertiaRedirector;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
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
        // このフォールバックが共通エラーページ（issue #70）の差し込み口になる。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return app(StaleResourceRedirector::class)->handle($e, $request);
        });

        // セッション切れ状態でのInertia非GETリクエスト（DELETE/PUT/PATCH）が、ログイン画面への
        // 302リダイレクトを経由して405になる問題への対応（issue #63・①）。詳細は
        // UnauthenticatedInertiaRedirector のクラスコメントを参照。
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return app(UnauthenticatedInertiaRedirector::class)->handle($e, $request);
        });

        // CSRFトークン不一致（419）は issue #70（共通エラーページ、PR #77）の
        // ErrorPageResponder::respond() に一本化して対応する。ここでは扱わない
        // （経緯は issue #63 のコメント参照）。
    })->create();
