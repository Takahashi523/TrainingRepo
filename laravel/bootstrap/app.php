<?php

use App\Exceptions\StaleResourceRedirector;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
        // 許可外の Host は 400 Bad Request になる。この例外は $dontReport 対象で既定では
        // 何も記録されないため、切り分け用の warning を withExceptions 側で別途出している。
        // 許可するのは「APP_URL のホスト」＋「TRUSTED_HOSTS（カンマ区切り・任意）」。
        //
        // APP_URL 単独導出だと、許可ホストを一時的に増やす操作がすべてコード変更＋再デプロイになる。
        // 実際にそれが必要になるのは次の2ケースで、いずれも .env だけで完結させたい：
        //   - ホスト名の移行中に新旧2ホストを同時に通したいとき（無停止で切り替えるため）
        //   - ロードバランサ / 監視が APP_URL 以外の名前（IP 直打ち等）で /up を叩くとき
        // TRUSTED_HOSTS は「追加」であって置き換えではないため、
        // 未設定時の挙動（APP_URL のホストのみ許可）は従来どおり。
        $middleware->trustHosts(
            at: function () {
                $hosts = array_merge(
                    [parse_url((string) config('app.url'), PHP_URL_HOST)],
                    explode(',', (string) config('app.trusted_hosts')),
                );

                $patterns = [];

                foreach ($hosts as $host) {
                    $host = trim((string) $host);

                    if ($host === '') {
                        continue;
                    }

                    // ⚠️ 渡す値は正規表現パターンで、Symfony は preg_match で**部分一致**を取る。
                    //    アンカーとエスケープはここで必ず付ける（TrustHostsTest で固定）。
                    $patterns[] = '^'.preg_quote($host).'$';
                }

                // ⚠️ 許可リストが空だと Symfony は「パターンが無い＝検証しない」と判断し、
                //    全ホストを素通しする（フェイルオープン）。APP_URL が空・不正のときは
                //    決して一致しないパターンを返し、閉じる側へ倒す。
                //
                // ⚠️ ホスト名を変更したら .env の APP_URL も必ず更新すること。
                //    更新を忘れると全リクエストが 400 になる
                //    （docs/環境構築手順書.md「リリース前チェックリスト」参照）。
                return $patterns ?: ['^(?!)$'];
            },
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

        // 許可外の Host ヘッダー（trustHosts の拒否）など、Symfony が不正と判定したリクエストを記録する。
        //
        // この例外は Handler の internalDontReport 対象で、既定ではログに何も残らない。
        // 一方これは APP_URL が実ホストとずれた瞬間に全リクエストが 400 になる経路でもあり、
        // 無言で落ちると原因の切り分け手段が無くなる（Silent Rejection）。
        // 応答は既定の 400 のままとし（null を返す）、運用者向けの手掛かりだけを残す。
        //
        // ⚠️ 型は SuspiciousOperationException ではなく BadRequestHttpException にすること。
        //    Handler::render() は renderViaCallbacks() の**前**に prepareException() を通し
        //    （Handler.php:651-653）、RequestExceptionInterface を BadRequestHttpException に
        //    差し替える（同 :710）。元例外で型宣言するとコールバックは一度も呼ばれない。
        //    元の例外は getPrevious() に保持されるため、そちらで判別する。
        $exceptions->render(function (BadRequestHttpException $e, Request $request) {
            // ⚠️ prepareException() は RequestExceptionInterface 以外も BadRequestHttpException にするため、
            //    元例外を必ず確認する。このガードを外すと、アプリが投げたあらゆる 400 が
            //    「Host 拒否」として記録され、ログの意味が壊れる（TrustHostsTest で固定）。
            $previous = $e->getPrevious();

            if (! $previous instanceof SuspiciousOperationException) {
                return null;
            }

            try {
                // 未認証・レート制限前の経路であり、不正な Host を投げ続けるだけで
                // ログを無制限に肥大させられる。1分あたりの件数を上限で抑える。
                //
                // ⚠️ キーは固定文字列＝全ホストで予算を共有する。公開 IP への無差別スキャンは
                //    常時発生するため、上限を絞りすぎると「運用者が本当に見たい1件が
                //    ノイズに押し出されて記録されない」＝障害調査のときに限ってログが空、
                //    という壊れ方をする。1リクエストにつき1件しか出ない経路なので、
                //    ログ量よりも取りこぼさないことを優先して余裕を持たせる。
                RateLimiter::attempt('untrusted-host-log', 60, function () use ($request, $previous) {
                    Log::warning('不正なリクエストを拒否しました（Host ヘッダー等）', [
                        // SuspiciousOperationException は Invalid Host / Untrusted Host /
                        // Invalid HTTP method override の3経路で送出される。どれなのかは
                        // 元例外のメッセージにしか出ないため、切り分け用に残す。
                        'reason' => Str::limit($previous->getMessage(), 200),
                        // Host は攻撃者が任意長を送れるため切り詰める
                        'host' => Str::limit((string) $request->headers->get('HOST'), 100),
                        'path' => Str::limit($request->path(), 100),
                        // ⚠️ $request->ip() は使わない。例外は TrustProxies の内部から送出され、
                        //    その時点で信頼プロキシが空にリセットされているため、常に直前の
                        //    接続元（＝前段 Nginx の固定 IP）しか返らず調査の役に立たない。
                        'remote_addr' => $request->server->get('REMOTE_ADDR'),
                        'x_forwarded_for' => Str::limit((string) $request->headers->get('X-Forwarded-For'), 100),
                    ]);
                }, 60);
            } catch (Throwable) {
                // ログ出力の失敗で 400 応答を 500 に化けさせない。
                // ここでの握りつぶしは意図的（応答の正しさを優先する）。
            }

            return null;
        });
    })->create();
