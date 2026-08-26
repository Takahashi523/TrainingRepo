<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * セッション切れ状態でのInertia非GETリクエスト（DELETE/PUT/PATCH）が、ログイン画面への
 * 302リダイレクトを経由して405（MethodNotAllowedHttpException）になる問題への対応（issue #63）。
 *
 * 「Inertiaかどうか」で応答を出し分けるのが対応の核なので、同じ未ログイン状態を
 * Inertiaリクエスト／非Inertiaリクエストの両方で通し、後者がLaravel標準の挙動
 * （302 / JSONなら401）のまま変わっていないことも確認する。
 */
class UnauthenticatedInertiaRedirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Inertia のリクエストとして扱わせるためのヘッダー。
     *
     * GET はバージョン不一致だと Inertia ミドルウェアが 409（強制リロード）を返すため、
     * サーバーと同じ計算方法（マニフェストのハッシュ）でバージョンを合わせる。
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()),
        ];
    }

    public function test_inertia_delete_while_unauthenticated_returns_409_with_inertia_location_header(): void
    {
        $project = Project::factory()->create();

        $response = $this->delete("/projects/{$project->id}", [], $this->inertiaHeaders());

        // 302ではなく409で返し、ブラウザにDELETEを引き継がせずに済ませる。
        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));

        // 対象は削除されていないこと（認証エラーであり、削除処理自体は実行されていない）。
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_inertia_put_while_unauthenticated_returns_409_with_inertia_location_header(): void
    {
        $project = Project::factory()->create();
        $originalName = $project->name;

        $response = $this->put("/projects/{$project->id}", ['name' => 'unauthenticated update attempt'], $this->inertiaHeaders());

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));

        // 更新も実行されていないこと（DELETE側のassertDatabaseHasと対称に確認する）。
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => $originalName]);
    }

    public function test_inertia_get_while_unauthenticated_also_returns_409_with_inertia_location_header(): void
    {
        // GET/HEAD/POSTは元々405にはならないが、セッション切れならどのメソッドでも
        // 同じ経路（AuthenticationException）を通るため、GETでも409に統一されることを確認する。
        $response = $this->get('/dashboard', $this->inertiaHeaders());

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));
    }

    public function test_inertia_get_while_unauthenticated_redirects_to_originally_intended_url_after_login(): void
    {
        // Inertia::location()はredirect()->guest()を経由しないため、intended URLの保存を
        // 自前で行っている（UnauthenticatedInertiaRedirector::rememberIntendedUrl）。
        // ここが抜けると、再ログイン後に必ずダッシュボードへ飛んでしまう（回帰確認）。
        $user = User::factory()->create();

        $response = $this->get('/engineers', $this->inertiaHeaders());

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));
        $this->assertSame(route('engineers.index'), session('url.intended'));

        $loginResponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // ダッシュボードではなく、セッション切れ前に開いていた元の画面へ戻ること。
        $loginResponse->assertRedirect(route('engineers.index'));
    }

    public function test_inertia_delete_while_unauthenticated_with_external_referer_does_not_remember_it(): void
    {
        // Refererは送信側が自由に書き換えられるため、外部ドメインをそのままurl.intendedへ
        // 保存すると、ログイン後にredirect()->intended()が外部URLへ遷移させてしまう
        // （オープンリダイレクト、issue #63 再レビュー指摘）。
        // UnauthenticatedInertiaRedirector::rememberIntendedUrl が自ホスト以外のRefererを
        // 弾いていることを確認する。
        $user = User::factory()->create();
        $project = Project::factory()->create();

        $headers = array_merge($this->inertiaHeaders(), [
            'Referer' => 'https://evil.example.com/phishing',
        ]);

        $response = $this->delete("/projects/{$project->id}", [], $headers);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('login'));

        // 外部ドメインのRefererはurl.intendedに保存されないこと。
        $this->assertNull(session('url.intended'));

        $loginResponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        // url.intendedが保存されていないため、ログイン後は既定のダッシュボードへ戻ること
        // （攻撃者が指定した外部URLへは飛ばない）。
        $loginResponse->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_non_inertia_delete_while_unauthenticated_still_returns_plain_302_redirect(): void
    {
        // X-Inertiaヘッダーなし（通常のブラウザ直叩き・非Inertiaクライアント）は
        // Laravel標準の挙動のまま変えない。
        $project = Project::factory()->create();

        $response = $this->delete("/projects/{$project->id}");

        $response->assertRedirect('/login');
        $response->assertStatus(302);
    }

    public function test_non_inertia_json_request_while_unauthenticated_still_returns_401(): void
    {
        // APIクライアント等、JSONを期待するリクエストはLaravel標準どおり401 JSONのまま。
        $response = $this->deleteJson('/projects/1');

        $response->assertStatus(401);
    }
}
