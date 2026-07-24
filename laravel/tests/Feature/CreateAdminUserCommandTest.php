<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_valid_input(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => '初期 管理者',
            '--email' => 'admin@example.com',
            '--password' => 'adminpass123',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
        $user = User::where('email', 'admin@example.com')->first();
        $this->assertTrue(Hash::check('adminpass123', $user->password));
    }

    public function test_rejects_weak_password(): void
    {
        $this->artisan('app:create-admin', [
            '--name' => 'X',
            '--email' => 'weak@example.com',
            '--password' => 'onlyletters',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
    }

    public function test_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->artisan('app:create-admin', [
            '--name' => 'X',
            '--email' => 'dup@example.com',
            '--password' => 'adminpass123',
        ])->assertExitCode(1);
    }

    public function test_enforces_email_domain_when_configured(): void
    {
        config(['master.allowed_email_domains' => ['nexus.example.com']]);

        $this->artisan('app:create-admin', [
            '--name' => 'X',
            '--email' => 'admin@gmail.com',
            '--password' => 'adminpass123',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'admin@gmail.com']);
    }
}
