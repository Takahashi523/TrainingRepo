<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRFトークン不一致（419、主にセッション切れ後の再送信で発生）で生の例外画面が出る
 * 問題への対応（issue #63・②）。
 *
 * ValidateCsrfToken ミドルウェアは web グループの中でも HandleInertiaRequests（Inertia
 * \Middleware）より前に位置するため、TokenMismatchException は①（AuthenticationException）
 * と同様、HandleInertiaRequests の後処理（非GETへの302→303書き換え）を通らずに例外
 * ハンドラまで巻き上がる。back() が返す素の302をそのまま返すと、非GET（DELETE/PUT/PATCH）
 * リクエストではブラウザがメソッドを保持したままリダイレクト先を叩き直してしまう
 * （303と違い、302でダウングレードされるのはPOSTだけ）ため、①と同じ理由で明示的に
 * 303へ書き換える必要がある（StaleResourceRedirector, issue #44 と同じ対応）。
 *
 * ①と異なりログイン画面へ強制的に飛ばすのではなく、元の画面に留めたまま「もう一度
 * お試しください」と案内する（issue本文の提案どおり）。CSRFトークン切れは「画面は
 * まだ開けているが、送信時にトークンが無効化されていた」状態であり、①のような
 * 認証エラー（そもそも見せてはいけない画面）とは性質が異なるため。
 */
final class TokenMismatchInertiaRedirector
{
    /**
     * Inertiaリクエストであれば、元の画面へ戻す応答（back() + フラッシュ）を返す。
     *
     * 非Inertia（APIコール・テストからの直接リクエスト等）はLaravel標準の挙動
     * （419のまま）に委ねたいので null を返す。
     */
    public function handle(TokenMismatchException $e, Request $request): ?Response
    {
        if (! $request->inertia()) {
            return null;
        }

        // ValidateCsrfToken は GET/HEAD/OPTIONS を検証対象外にしている（isReading()）ため、
        // この例外はDELETE/PUT/PATCH等の非安全メソッドでしか発生しない。①（AuthenticationException,
        // GETでも発生しうる）と違い isMethodSafe() による分岐は不要で、常に303を返してよい。
        //
        // 参照元が取れない場合（セッションが新規発行され _previous.url が無い等）でも
        // 行き先を失わないよう、StaleResourceRedirector の SavedSearch ケースと同様に
        // ダッシュボードへのフォールバックを指定する。
        return back(fallback: route('dashboard'))
            ->with('error', 'セッションの有効期限が切れました。もう一度お試しください。')
            ->setStatusCode(303);
    }
}
