<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * 初期管理者ユーザーを作成するコマンド。
 *
 * マスタ管理（/master）は管理者専用で自己登録もないため、リリース時の最初の管理者を
 * 安全に用意する手段として提供する。平文パスワードを .env やリポジトリに残さず、
 * 対話入力（--password 省略時は非表示入力）で作成できる。
 *
 * 例:
 *   php artisan app:create-admin
 *   php artisan app:create-admin --name="山田 太郎" --email=admin@example.com --password=secret123
 */
class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {--name= : 氏名} {--email= : メールアドレス（ログインID）} {--password= : パスワード（8文字以上・英字と数字）}';

    protected $description = '初期管理者ユーザーを作成する';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('氏名');
        $email = $this->option('email') ?: $this->ask('メールアドレス（ログインID）');
        $password = $this->option('password') ?: $this->secret('パスワード（8文字以上・英字と数字を含む）');

        $data = [
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email'), ...$this->domainRule()],
            'password' => ['required', 'max:255', Password::defaults()],
        ], $this->domainMessage(), [
            'name' => '氏名',
            'email' => 'メールアドレス',
            'password' => 'パスワード',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
        ]);

        $this->info("管理者ユーザーを作成しました：{$user->name} <{$user->email}>");

        return self::SUCCESS;
    }

    /**
     * 許容ドメインが設定されていれば ends_with 制限を付ける（マスタ管理の登録と同じ扱い）。
     *
     * @return array<int, string>
     */
    private function domainRule(): array
    {
        $domains = config('organization.allowed_email_domains');

        if (empty($domains)) {
            return [];
        }

        return ['ends_with:'.implode(',', array_map(fn ($d) => '@'.$d, $domains))];
    }

    /**
     * 許容ドメインを明示する ends_with のカスタムメッセージ（マスタ管理の登録と揃える）。
     *
     * @return array<string, string>
     */
    private function domainMessage(): array
    {
        $domains = config('organization.allowed_email_domains');

        if (empty($domains)) {
            return [];
        }

        $list = implode('、', array_map(fn ($d) => '@'.$d, $domains));

        return ['email.ends_with' => "社内メールアドレス（{$list}）で登録してください。"];
    }
}
