<?php

namespace Tests\Feature;

use App\Models\FormFieldSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterFormSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function general(): User
    {
        return User::factory()->create(['role' => 'general']);
    }

    private function setting(array $overrides = []): FormFieldSetting
    {
        return FormFieldSetting::create(array_merge([
            'form_type' => 'engineer',
            'field_key' => 'birth_date',
            'is_required' => false,
            'is_system_required' => false,
        ], $overrides));
    }

    // -------------------------------------------------------
    // 認可
    // -------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->put('/master/form-settings', ['settings' => []])->assertRedirect('/login');
    }

    public function test_general_user_is_forbidden(): void
    {
        $this->actingAs($this->general())
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertForbidden();
    }

    // -------------------------------------------------------
    // 更新
    // -------------------------------------------------------

    public function test_admin_can_toggle_field_and_records_updated_by(): void
    {
        $admin = $this->admin();
        $this->setting(['field_key' => 'birth_date', 'is_required' => false]);

        // 即時反映トグルはフラッシュ（トースト）を出さない（行内フィードバックに一本化）。
        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertRedirect(route('master.index'))
            ->assertSessionMissing('success');

        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer',
            'field_key' => 'birth_date',
            'is_required' => true,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_system_required_field_is_ignored(): void
    {
        $admin = $this->admin();
        $this->setting([
            'field_key' => 'name',
            'is_required' => true,
            'is_system_required' => true,
        ]);

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'name', 'is_required' => false],
                ],
            ])
            ->assertRedirect(route('master.index'));

        // 固定必須は変更されない
        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer',
            'field_key' => 'name',
            'is_required' => true,
        ]);
    }

    public function test_nonexistent_field_key_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'not_a_field', 'is_required' => true],
                ],
            ])
            ->assertSessionHasErrors('settings.0.field_key');
    }

    public function test_invalid_form_type_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'unknown', 'field_key' => 'birth_date', 'is_required' => true],
                ],
            ])
            ->assertSessionHasErrors('settings.0.form_type');
    }

    public function test_can_update_engineer_and_project_settings_together(): void
    {
        $admin = $this->admin();
        $this->setting(['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => false]);
        $this->setting(['form_type' => 'project', 'field_key' => 'client_name', 'is_required' => false]);

        $this->actingAs($admin)
            ->put('/master/form-settings', [
                'settings' => [
                    ['form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true],
                    ['form_type' => 'project', 'field_key' => 'client_name', 'is_required' => true],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'engineer', 'field_key' => 'birth_date', 'is_required' => true,
        ]);
        $this->assertDatabaseHas('form_field_settings', [
            'form_type' => 'project', 'field_key' => 'client_name', 'is_required' => true,
        ]);
    }
}
