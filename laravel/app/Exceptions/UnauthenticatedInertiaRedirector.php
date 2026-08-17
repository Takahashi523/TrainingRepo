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
 * ②（TokenMismatchInertiaRedirector、419対応）とは異なりログイン画面への強制遷移が
 * 必要なため、303への書き換え（Inertia内で完結する遷移）ではなく、Inertia公式の
 * 「external redirect」の仕組み（409 + X-Inertia-Location によるフルページ遷移）を使う。
 * 単純な303リダイレクトでも遷移自体はできるが、セッション切れ後はCookie・CSRFトークン・
 * Inertiaのバージョン情報などクライアント側の状態が古くなっている可能性があるため、
 * 素のブラウザ遷移でまっさらな状態から読み込ませる方が安全。
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

        // $e->redirectTo() は、ガードごとに個別のリダイレクト先を登録できる仕組み
        // （AuthenticationException::redirectUsing()）。現状このアプリはログイン画面が
        // 1つだけなので常に null になり route('login') にフォールバックするが、Laravel
        // 標準の unauthenticated() と同じ書き方に揃えておくことで、将来ガードが増えても
        // 個別リダイレクト先の登録だけで対応できるようにしている。
        return Inertia::location($e->redirectTo($request) ?? route('login'));
    }
}
