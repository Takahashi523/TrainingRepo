<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * UserService の並行制御・防波堤ロジックを Service 直呼びで検証する。
 * - FormRequest の unique チェックと DB 一意制約の間の並行競合（同一メール同時作成/更新）が
 *   500 ではなく email の 422（ValidationException）に変換されること。
 * - 最後の管理者削除ガード（並行時の COUNT→DELETE の間に admin が1名になるケースの最終防波堤）。
 */
class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UserService
    {
        return app(UserService::class);
    }

    public function test_store_converts_duplicate_email_to_validation_error(): void
    {
        User::factory()->create(['email' => 'race@example.com']);

        try {
            $this->service()->store([
                'name' => '競合 太郎',
                'email' => 'race@example.com',
                'password' => 'password123',
                'role' => 'general',
            ]);
            $this->fail('ValidationException が送出されるべき');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // 重複ユーザーは作られていない（トランザクションがロールバックされる）
        $this->assertSame(1, User::where('email', 'race@example.com')->count());
    }

    public function test_update_converts_duplicate_email_to_validation_error(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $target = User::factory()->create(['email' => 'target@example.com', 'role' => 'general']);

        try {
            $this->service()->update($target, [
                'name' => $target->name,
                'email' => 'taken@example.com',
                'role' => 'general',
            ]);
            $this->fail('ValidationException が送出されるべき');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        // 変更はロールバックされ、元のメールのまま
        $this->assertSame('target@example.com', $target->fresh()->email);
    }

    /**
     * 最後の管理者削除ガード（並行制御の最終防波堤）を Service 直呼びで検証する。
     *
     * HTTP 単一リクエストでは、最後の管理者を削除できるのは本人のみ（管理者専用ルート）で
     * 自己削除ガードが先に発火するため、DeleteUserRequest の最後の管理者チェックには到達しない。
     * 実際に効くのは本 Service のロック付きガードであり、2管理者を同時削除／一方を降格＋他方を削除
     * といった並行時（FormRequest の COUNT は通過するが delete 時点で admin が1名）を模した検証となる。
     */
    public function test_delete_rejects_last_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        try {
            $this->service()->delete($admin);
            $this->fail('ValidationException が送出されるべき');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('delete', $e->errors());
        }

        // 最後の管理者は削除されず残っている
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_delete_allows_admin_when_another_admin_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']); // もう1名の管理者

        $this->service()->delete($admin);

        // 管理者が2名いれば削除可（過剰にブロックしない）
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        $this->assertSame(1, User::where('role', 'admin')->count());
    }

    /**
     * 最後の管理者「降格」ガード（UserService::guardLastAdminOnDemotion）を Service 直呼びで検証する
     * （2026-09-02 追加。上の削除ガード検証と対になるもの）。
     *
     * HTTP 単一リクエストでは、最後の管理者を降格できるのは本人のみ（管理者専用ルート）で、
     * UpdateUserRequest::withValidator() の自己ロール変更ガードが先に発火して return するため、
     * 「最後の管理者」チェック（$demotingAdmin && adminCount <= 1）には到達しない
     * （actor ≠ target なら actor 自身も管理者としてカウントされ、adminCount は必ず2以上になるため）。
     * つまりこの分岐は通常操作の中だけでは単独確認できない、実質的にService層でしか検証できないガード。
     */
    public function test_update_rejects_demoting_the_only_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        try {
            $this->service()->update($admin, [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'general',
            ]);
            $this->fail('ValidationException が送出されるべき');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }

        // 最後の管理者は降格されず admin のまま残っている
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_update_allows_demoting_admin_when_another_admin_exists(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']); // もう1名の管理者

        $this->service()->update($admin, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'general',
        ]);

        // 管理者が2名いれば降格可（過剰にブロックしない）
        $this->assertSame('general', $admin->fresh()->role);
    }
}
