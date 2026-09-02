<?php

namespace Tests\Feature;

use App\Models\Engineer;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\FormFieldSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MasterUserControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------

    private function admin(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'admin'], $overrides));
    }

    private function general(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => 'general'], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '新規 太郎',
            'email' => 'shinki@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'general',
        ], $overrides);
    }

    // -------------------------------------------------------
    // 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/master')->assertRedirect('/login');
    }

    public function test_general_user_is_forbidden_from_index(): void
    {
        $this->actingAs($this->general())->get('/master')->assertForbidden();
    }

    public function test_admin_can_view_index(): void
    {
        $this->actingAs($this->admin())->get('/master')->assertOk();
    }

    public function test_general_user_is_forbidden_from_mutations(): void
    {
        $general = $this->general();
        $target = $this->general();

        $this->actingAs($general)->post('/master/users', $this->validPayload())->assertForbidden();
        $this->actingAs($general)->put("/master/users/{$target->id}", $this->validPayload())->assertForbidden();
        $this->actingAs($general)->delete("/master/users/{$target->id}")->assertForbidden();
    }

    public function test_index_returns_users_and_form_settings_props(): void
    {
        config(['organization.allowed_email_domains' => []]);
        $this->seed(FormFieldSettingSeeder::class);
        $admin = $this->admin(['name' => 'アルファ管理者', 'email' => 'alpha@example.com']);

        $this->actingAs($admin)->get('/master')
            ->assertInertia(fn ($page) => $page
                ->component('Master/Index')
                // ->has だけでなく実際のフィールド値まで検証する
                // （Resource が空配列にシリアライズされる不具合を検知するため）
                ->has('users', 1)
                ->where('users.0.name', 'アルファ管理者')
                ->where('users.0.email', 'alpha@example.com')
                ->where('users.0.role_label', '管理者')
                ->has('form_settings.engineer')
                ->where('form_settings.engineer.0.field_label', fn ($label) => filled($label))
                ->has('form_settings.project')
                ->where('form_settings.project.0.field_label', fn ($label) => filled($label))
                // 未設定時はドメインヒント用の配列が空で渡る
                ->where('allowed_email_domains', [])
            );
    }

    public function test_index_exposes_allowed_email_domains_when_configured(): void
    {
        // 管理者専用画面に限りヒント表示用のドメインを渡す（公開画面には出さない方針）。
        config(['organization.allowed_email_domains' => ['nexus.co.jp', 'nexus.example.com']]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/master')
            ->assertInertia(fn ($page) => $page
                ->where('allowed_email_domains', ['nexus.co.jp', 'nexus.example.com'])
            );
    }

    public function test_index_users_are_ordered_by_name_then_id(): void
    {
        $admin = $this->admin(['name' => '同名 太郎', 'email' => 'a@example.com']);
        $second = $this->general(['name' => '同名 太郎', 'email' => 'b@example.com']);
        $this->general(['name' => 'あ 花子', 'email' => 'c@example.com']);

        // 同名（同名 太郎）は id 昇順でタイブレークされ、決定的な順序になる
        $this->actingAs($admin)->get('/master')
            ->assertInertia(fn ($page) => $page
                ->where('users.1.id', $admin->id)
                ->where('users.2.id', $second->id)
            );
    }

    // -------------------------------------------------------
    // 追加（store）
    // -------------------------------------------------------

    public function test_admin_can_create_user(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/master/users', $this->validPayload())
            ->assertRedirect(route('master.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'shinki@example.com',
            'role' => 'general',
        ]);
        $created = User::where('email', 'shinki@example.com')->first();
        $this->assertTrue(Hash::check('password123', $created->password));
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->admin();
        $this->general(['email' => 'dup@example.com']);

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['email' => 'dup@example.com']))
            ->assertSessionHasErrors('email');
    }

    public function test_password_without_number_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload([
                'password' => 'passwordonly',
                'password_confirmation' => 'passwordonly',
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload([
                'password_confirmation' => 'different123',
            ]))
            ->assertSessionHasErrors('password_confirmation');
    }

    public function test_password_exceeding_max_length_is_rejected(): void
    {
        $admin = $this->admin();
        // 256文字（英字+数字を含むので max:255 のみ違反する）
        $long = str_repeat('ab12', 64);
        $this->assertSame(256, strlen($long));

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload([
                'password' => $long,
                'password_confirmation' => $long,
            ]))
            ->assertSessionHasErrors('password');
    }

    public function test_invalid_role_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['role' => 'superuser']))
            ->assertSessionHasErrors('role');
    }

    public function test_validation_messages_use_japanese_attribute_names(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['name' => '', 'email' => '']))
            ->assertSessionHasErrors([
                'name' => '氏名は必須です。',
                'email' => 'メールアドレスは必須です。',
            ]);
    }

    public function test_email_domain_restriction_rejects_disallowed_domain(): void
    {
        config(['organization.allowed_email_domains' => ['nexus.example.com']]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['email' => 'user@gmail.com']))
            ->assertSessionHasErrors([
                'email' => '社内メールアドレス（@nexus.example.com）で登録してください。',
            ]);
    }

    public function test_email_domain_restriction_allows_configured_domain(): void
    {
        config(['organization.allowed_email_domains' => ['nexus.example.com']]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['email' => 'user@nexus.example.com']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('master.index'));
    }

    public function test_no_domain_restriction_when_unset(): void
    {
        config(['organization.allowed_email_domains' => []]);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/master/users', $this->validPayload(['email' => 'anyone@anydomain.co.jp']))
            ->assertSessionHasNoErrors();
    }

    // -------------------------------------------------------
    // 編集（update）
    // -------------------------------------------------------

    public function test_admin_can_update_user(): void
    {
        $admin = $this->admin();
        $target = $this->general(['name' => '旧名', 'email' => 'old@example.com']);

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => '新名',
            'email' => 'new@example.com',
            'role' => 'admin',
            'version' => 0,
        ])->assertRedirect(route('master.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => '新名',
            'email' => 'new@example.com',
            'role' => 'admin',
        ]);
    }

    // -------------------------------------------------------
    // update: PUT /master/users/{user} — 楽観ロック（version, issue #45）
    // -------------------------------------------------------

    public function test_update_increments_version_when_version_matches(): void
    {
        $admin = $this->admin();
        $target = $this->general(['name' => '旧名']);

        // factory の create() は DB 側の DEFAULT (0) を明示的に返さないため、
        // モデル属性ではなく DB を見て前提（初期 version=0）を確認する。
        $this->assertDatabaseHas('users', ['id' => $target->id, 'version' => 0]);

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => '新名',
            'email' => $target->email,
            'role' => 'general',
            'version' => 0,
        ])->assertRedirect(route('master.index'));

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => '新名',
            'version' => 1,
        ]);
    }

    public function test_update_rejects_stale_version_and_keeps_the_winning_update(): void
    {
        $admin = $this->admin();
        $target = $this->general(['name' => '元の氏名']);

        $winnerResponse = $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => '先勝ちの氏名',
            'email' => $target->email,
            'role' => 'general',
            'version' => 0,
        ]);
        $winnerResponse->assertRedirect(route('master.index'));

        $loserResponse = $this->actingAs($admin)->from(route('master.index'))->put("/master/users/{$target->id}", [
            'name' => '後追いの氏名',
            'email' => $target->email,
            'role' => 'general',
            'version' => 0,
        ]);

        $loserResponse->assertRedirect(route('master.index'));
        $loserResponse->assertSessionHas('error', '他のユーザーがこのユーザー情報を更新しました。最新のデータを表示しました。');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => '先勝ちの氏名',
            'version' => 1,
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => $target->id,
            'name' => '後追いの氏名',
        ]);
    }

    public function test_update_requires_version_field(): void
    {
        $admin = $this->admin();
        $target = $this->general();

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
        ])->assertSessionHasErrors('version');
    }

    public function test_password_is_unchanged_when_omitted_on_update(): void
    {
        $admin = $this->admin();
        $target = $this->general();

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
            'version' => 0,
        ])->assertSessionHasNoErrors();

        // Factory の初期パスワードは 'password'
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_password_is_changed_when_provided_on_update(): void
    {
        $admin = $this->admin();
        $target = $this->general();

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
            'password' => 'brandnew123',
            'password_confirmation' => 'brandnew123',
            'version' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brandnew123', $target->fresh()->password));
    }

    public function test_update_requires_confirmation_when_password_provided(): void
    {
        $admin = $this->admin();
        $target = $this->general();

        // パスワードを入力したのに確認欄が空 → password_confirmation にエラー
        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
            'password' => 'newpass123',
            'password_confirmation' => '',
            'version' => 0,
        ])->assertSessionHasErrors('password_confirmation');
    }

    public function test_update_password_exceeding_max_length_is_rejected(): void
    {
        $admin = $this->admin();
        $target = $this->general();
        $long = str_repeat('ab12', 64); // 256文字

        $this->actingAs($admin)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
            'password' => $long,
            'password_confirmation' => $long,
            'version' => 0,
        ])->assertSessionHasErrors('password');
    }

    public function test_updating_own_email_is_not_treated_as_duplicate(): void
    {
        $admin = $this->admin(['email' => 'me@example.com']);

        $this->actingAs($admin)->put("/master/users/{$admin->id}", [
            'name' => '自分',
            'email' => 'me@example.com',
            'role' => 'admin',
            'version' => 0,
        ])->assertSessionHasNoErrors();
    }

    /**
     * 【2026-09-02 改名】旧名 test_demoting_last_admin_is_rejected。
     *
     * 管理者が自分1人だけの状態で自己降格を試みるケース。actor === target のため、
     * UpdateUserRequest::withValidator() は「自分自身のロール変更禁止」ガードで先に return し、
     * 「最後の管理者」ガード（$demotingAdmin && adminCount <= 1）には到達しない
     * （到達するには actor ≠ target が必要だが、その場合 actor 自身も管理者としてカウントされる
     * ため adminCount は必ず2以上になり、両立しえない）。つまりこのテストは
     * test_changing_own_role_is_rejected_even_when_another_admin_exists() と同じ自己降格ガードを
     * 別のDB状態（管理者1人）で確認しているだけで、「最後の管理者」ガード自体の検証にはならない。
     * メッセージまで検証することで、どちらのガードが発火したかを明示しておく。
     * 「最後の管理者」ガード本体の検証は tests/Feature/UserServiceTest.php の
     * test_update_rejects_demoting_the_only_admin() を参照。
     */
    public function test_self_demotion_as_sole_admin_is_rejected_by_self_guard(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put("/master/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => 'general',
            'version' => 0,
        ])->assertSessionHasErrors(['role' => '自分自身のロールは変更できません。']);

        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_demoting_admin_is_allowed_when_another_admin_exists(): void
    {
        $actor = $this->admin();
        $target = $this->admin();

        $this->actingAs($actor)->put("/master/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'general',
            'version' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertSame('general', $target->fresh()->role);
    }

    public function test_changing_own_role_is_rejected_even_when_another_admin_exists(): void
    {
        // 別の管理者がいるため last-admin ガードは発火しない。自己ロール変更ガード単体を検証する。
        $actor = $this->admin();
        $this->admin();

        $this->actingAs($actor)->put("/master/users/{$actor->id}", [
            'name' => $actor->name,
            'email' => $actor->email,
            'role' => 'general',
            'version' => 0,
        ])->assertSessionHasErrors('role');

        $this->assertSame('admin', $actor->fresh()->role);
    }

    public function test_admin_can_update_own_profile_without_changing_role(): void
    {
        // 自分自身でもロール以外（氏名・メール）は編集できる（ロール据え置きなら弾かれない）。
        $actor = $this->admin(['name' => '旧名', 'email' => 'old@example.com']);

        $this->actingAs($actor)->put("/master/users/{$actor->id}", [
            'name' => '新名',
            'email' => 'new@example.com',
            'role' => 'admin',
            'version' => 0,
        ])->assertSessionHasNoErrors();

        $fresh = $actor->fresh();
        $this->assertSame('新名', $fresh->name);
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertSame('admin', $fresh->role);
    }

    public function test_update_returns_404_for_missing_user(): void
    {
        $this->actingAs($this->admin())
            ->put('/master/users/999999', $this->validPayload())
            ->assertNotFound();
    }

    // -------------------------------------------------------
    // 削除（destroy）
    // -------------------------------------------------------

    public function test_admin_can_delete_unassigned_user(): void
    {
        $admin = $this->admin();
        $target = $this->general();

        $this->actingAs($admin)->delete("/master/users/{$target->id}")
            ->assertRedirect(route('master.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_deleting_user_nullifies_sub_user_references(): void
    {
        $admin = $this->admin();
        $mainUser = $this->general();
        $subUser = $this->general();

        $engineer = Engineer::factory()->create([
            'main_user_id' => $mainUser->id,
            'sub_user_id' => $subUser->id,
        ]);

        $this->actingAs($admin)->delete("/master/users/{$subUser->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseHas('engineers', [
            'id' => $engineer->id,
            'sub_user_id' => null,
        ]);
    }

    public function test_self_deletion_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->delete("/master/users/{$admin->id}")
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_deletion_is_rejected_when_user_is_main_engineer(): void
    {
        $admin = $this->admin();
        $target = $this->general();
        Engineer::factory()->create(['main_user_id' => $target->id]);

        $this->actingAs($admin)->delete("/master/users/{$target->id}")
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_deletion_is_rejected_when_user_is_main_project(): void
    {
        $admin = $this->admin();
        $target = $this->general();
        Project::factory()->create(['main_user_id' => $target->id]);

        $this->actingAs($admin)->delete("/master/users/{$target->id}")
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_deletion_boundary_zero_assignments_is_allowed(): void
    {
        $admin = $this->admin();
        $target = $this->general();
        // 別ユーザーの主担当としての案件（target は無担当）
        Project::factory()->create(['main_user_id' => $this->general()->id]);

        $this->actingAs($admin)->delete("/master/users/{$target->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_destroy_returns_404_for_missing_user(): void
    {
        $this->actingAs($this->admin())
            ->delete('/master/users/999999')
            ->assertNotFound();
    }
}
