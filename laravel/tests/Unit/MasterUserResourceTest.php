<?php

namespace Tests\Unit;

use App\Http\Resources\MasterUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class MasterUserResourceTest extends TestCase
{
    public function test_admin_role_is_labeled_and_last_login_is_iso8601(): void
    {
        $user = User::factory()->make([
            'role' => 'admin',
            'last_login_at' => now(),
        ]);

        $array = (new MasterUserResource($user))->toArray(Request::create('/master'));

        $this->assertSame('admin', $array['role']);
        $this->assertSame('管理者', $array['role_label']);
        $this->assertNotNull($array['last_login_at']);
    }

    public function test_general_role_is_labeled_and_null_last_login_is_null(): void
    {
        $user = User::factory()->make([
            'role' => 'general',
            'last_login_at' => null,
        ]);

        $array = (new MasterUserResource($user))->toArray(Request::create('/master'));

        $this->assertSame('一般', $array['role_label']);
        $this->assertNull($array['last_login_at']);
    }
}
