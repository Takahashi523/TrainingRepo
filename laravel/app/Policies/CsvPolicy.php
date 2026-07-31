<?php

namespace App\Policies;

use App\Models\User;

/**
 * CSV 入出力の認可（O-3 確定）。
 *
 * ロール（admin / general）でのアクセス可否のみを判定し、レコード所有者による絞り込みは行わない
 * （全件を入出力可）。コントローラ内にロール判定を書かず（CLAUDE.md 準拠）、この Policy に集約する。
 * CsvController は非モデルの Gate ability（AppServiceProvider::boot で `Gate::define('access-csv', ...)`）
 * を通じてこのメソッドを呼び出す。未ログインは auth ミドルウェアが弾く。
 */
class CsvPolicy
{
    public function access(User $user): bool
    {
        return in_array($user->role, ['admin', 'general'], true);
    }
}
