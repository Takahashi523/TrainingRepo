<?php

use App\Exceptions\StaleResourceRedirector;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
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
        // リバースプロキシ（Nginx）配下で HTTPS を正しく認識させる。
        //
        // Nginx が TLS を終端し、後段の Laravel へは平文 HTTP で転送するため、
        // この設定が無いと $request->isSecure() が常に false になり、
        // asset() / @vite が生成する絶対 URL が http:// になって Mixed Content でブロックされる。
        //
        // at: '*' は「直前の接続元（REMOTE_ADDR）を信頼する」の意味。
        //   Docker のコンテナ IP は起動のたびに変わるため IP を固定列挙できない。
        //   ⚠️ この設定は「laravel コンテナのポートを外部公開しないこと」を前提に成立する。
        //      直接到達できる経路ができると、下記ヘッダーの偽装で
        //      レート制限（LoginRequest::throttleKey）の回避が成立してしまう。
        //
        // headers: は既定（6種）ではなく、Nginx が proxy_set_header で
        //   明示的にセットしている 2 種だけに絞る。
        //   Nginx がセットしないヘッダーはクライアントの送信値がそのまま素通しされるため、
        //   信頼することは「外部からの書き換えを許すこと」と等価になる。
        //   実際、X-Forwarded-Host / -Prefix / -Port は本番環境で注入可能なことを確認済み
        //   （生成される絶対 URL のホスト・パス・ポートを外部から差し替えられる状態だった）。
        //   なお 443 以外の非標準ポートで公開する構成に変える場合は、
        //   Nginx に X-Forwarded-Port を追加したうえで HEADER_X_FORWARDED_PORT の信頼を戻すこと。
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PROTO,
        );

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
    })->create();
