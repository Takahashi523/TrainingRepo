<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * FormRequest の unique チェックと DB の一意制約の間に生じる並行競合
 *（2管理者が同一メールを同時作成/更新）を Service 直呼びで再現し、
 * 500 ではなく email の 422（ValidationException）に変換されることを検証する。
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
}
