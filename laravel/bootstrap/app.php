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

        // Host ヘッダーによる絶対 URL の乗っ取りを防ぐ。
        //
        // trustProxies は X-Forwarded-* しか扱わないため、Host ヘッダー自体はクライアント制御下に残る。
        // Nginx の proxy_set_header Host $host; の $host も「クライアントが送った Host」由来であり、
        // 443 の server ブロックが1つ＝既定サーバのため、どんな Host を送っても素通りする。
        // 実際 Host: evil.example.com を送ると asset() が https://evil.example.com/... を生成していた。
        //
        // ⚠️ 渡す値は「正規表現パターン」であり、Symfony が preg_match で**部分一致**を取る
        //    （Request::setTrustedHosts が {%s}i で包むだけでアンカーを足さない）。
        //    'localhost' とそのまま書くと evil-localhost.attacker.com にも一致してしまうため、
        //    ^...$ で囲み preg_quote でエスケープすること。
        //
        // なお TrustHosts は local 環境とテスト実行時は自動的に無効になる（shouldSpecifyTrustedHosts）。
        // 許可外の Host は 400 Bad Request になり、例外は $dontReport 対象のためログは汚れない。
        $middleware->trustHosts(
            at: fn () => array_filter([
                // APP_URL のホスト。⚠️ ホスト名を変更したら .env の APP_URL も必ず更新すること。
                // 更新を忘れると全リクエストが 400 になる（docs/環境構築手順書.md のリリース前チェック参照）。
                ($appHost = parse_url((string) config('app.url'), PHP_URL_HOST))
                    ? '^'.preg_quote($appHost).'$'
                    : null,
                // コンテナ内からのヘルスチェック用。localhost を名乗られても生成される URL は
                // localhost 宛＝攻撃者の支配下ではないため、許可しても攻撃には使えない。
                '^localhost$',
            ]),
            subdomains: false,
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
