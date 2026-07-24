<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * マスタ管理：ユーザーの生成・更新・削除を担うサービス。
 * 既存 ProjectService / PipelineService と同様にトランザクション・並行制御を内包し、
 * コントローラを薄く保つ。
 */
class UserService
{
    /**
     * ユーザーを追加する。
     *
     * @param  array<string, mixed>  $data
     */
    public function store(array $data): User
    {
        return DB::transaction(function () use ($data) {
            try {
                return User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'role' => $data['role'],
                ]);
            } catch (QueryException $e) {
                $this->rethrowDuplicateEmail($e, $data['email']);
            }
        });
    }

    /**
     * ユーザーを更新する。パスワードは指定時のみ変更する。
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            // 最後の管理者を一般に降格する操作を行ロックで再検査（並行時のロストアップデート防止）。
            $this->guardLastAdminOnDemotion($user, $data['role']);

            $attributes = [
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
            ];

            if (! empty($data['password'])) {
                $attributes['password'] = Hash::make($data['password']);
            }

            try {
                $user->update($attributes);
            } catch (QueryException $e) {
                $this->rethrowDuplicateEmail($e, $data['email']);
            }

            return $user;
        });
    }

    /**
     * ユーザーを物理削除する。
     * 主担当（main_user_id）が残っている場合は FK RESTRICT で例外となるため、
     * これを捕捉して 422 に変換する（COUNT→DELETE 間の並行に対する最終防波堤）。
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            try {
                $user->delete();
            } catch (QueryException $e) {
                throw ValidationException::withMessages([
                    'delete' => '担当中の案件・人材が残っているため削除できません。別の担当者へ変更してから再度実行してください。',
                ]);
            }
        });
    }

    /**
     * DB の一意制約違反（メール重複）をユーザー向けの 422 に変換する。
     * FormRequest の unique チェックと DB 制約の間に生じる並行競合
     *（2管理者が同一メールを同時作成/更新）を吸収し、500 を防ぐ。
     *
     * SQLSTATE（23000）で判定しない：23000 は UNIQUE 違反だけでなく FK 違反も含み、
     * ドライバ依存コード（MySQL 1062 等）は SQLite テストで動かないため。
     * 代わりに **同一メールの行が実在するか** で判定する（ドライバ非依存・PR #48 と同方針）。
     * 重複でなければ元の例外を再送出する。
     */
    private function rethrowDuplicateEmail(QueryException $e, string $email): never
    {
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'このメールアドレスはすでに使用されています。',
            ]);
        }

        throw $e;
    }

    /**
     * 最後の管理者を一般ロールへ降格させようとしていないかを、行ロック付きで検査する。
     */
    private function guardLastAdminOnDemotion(User $user, string $newRole): void
    {
        if ($user->role !== 'admin' || $newRole === 'admin') {
            return;
        }

        $adminCount = User::where('role', 'admin')->lockForUpdate()->count();

        if ($adminCount <= 1) {
            throw ValidationException::withMessages([
                'role' => '最後の管理者を一般に変更できません。',
            ]);
        }
    }
}
