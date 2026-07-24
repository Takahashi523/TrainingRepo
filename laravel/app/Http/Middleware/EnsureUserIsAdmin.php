<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * マスタ管理など管理者専用ルートへのアクセスを制御するミドルウェア。
 *
 * コントローラ内にロール判定を書かず（CLAUDE.md 準拠）、ルートグループ単位で
 * 管理者ロールを担保する。一般営業（general）がアクセスした場合は 403 を返す。
 * ※自己削除・最後の管理者・担当中などの業務ルールは 422（FormRequest 側）で扱い、
 *   認可（403）とは区別する。
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'admin') {
            abort(403);
        }

        return $next($request);
    }
}
