<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_invalid_credentials_return_japanese_error_message(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        // lang/ja/auth.php の failed 文言（L2 指定）が errors.email に入ること。
        $response->assertSessionHasErrors([
            'email' => 'メールアドレスまたはパスワードが正しくありません',
        ]);
    }

    public function test_remember_me_sets_remember_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $this->assertAuthenticated();
        // remember_web_* クッキーが付与されること（Cookie 名は remember_web で始まる）。
        $this->assertNotNull(
            collect($response->headers->getCookies())
                ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web')),
        );
    }

    /**
     * 無効化した Breeze ルートに到達できないこと（register / forgot / reset）。
     */
    public function test_disabled_auth_routes_return_404(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password')->assertNotFound();
    }

    public function test_validation_errors_use_japanese_attribute_names(): void
    {
        // 空送信で required エラーの :attribute が英語キーではなく日本語表示名になること。
        $response = $this->from('/login')->post('/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertSessionHasErrors([
            'email' => 'メールアドレスは必須です。',
            'password' => 'パスワードは必須です。',
        ]);
    }

    public function test_invalid_email_format_uses_concise_japanese_message(): void
    {
        // email 形式エラーは :attribute を含まない簡潔な文言にする（LoginRequest::messages 上書き）。
        $response = $this->from('/login')->post('/login', [
            'email' => 'not-an-email',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors([
            'email' => '有効なメールアドレスを入力してください。',
        ]);
    }

    public function test_authenticated_user_is_redirected_from_login_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect('/dashboard');
    }

    public function test_login_rejects_disallowed_email_domain_when_configured(): void
    {
        // 許容ドメイン設定時、社外ドメインはバリデーション段階（認証前）で弾く（二重チェック）。
        config(['organization.allowed_email_domains' => ['nexus.example.com']]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'user@gmail.com',
            'password' => 'password',
        ]);

        // 未認証画面のため許可ドメイン名は出さず、汎用文言で伏せる（情報漏えい防止）。
        $response->assertSessionHasErrors([
            'email' => '社内メールアドレスでログインしてください。',
        ]);
        $errors = session('errors')->get('email');
        $this->assertNotEmpty($errors);
        $this->assertStringNotContainsString('nexus.example.com', $errors[0]);
        $this->assertGuest();
    }

    public function test_login_allows_configured_email_domain(): void
    {
        // 許容ドメインの既存ユーザーは通る。ログイン側では unique を付けないため
        // 「既に存在するメール」でも一意制約で弾かれず認証まで到達する。
        config(['organization.allowed_email_domains' => ['nexus.example.com']]);
        $user = User::factory()->create(['email' => 'coordinator@nexus.example.com']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_login_has_no_domain_restriction_when_unset(): void
    {
        // 未設定（既定）ではドメイン制限を掛けない（形式チェックのみ・TBD#3）。
        config(['organization.allowed_email_domains' => []]);
        $user = User::factory()->create(['email' => 'anyone@anydomain.co.jp']);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }
}
