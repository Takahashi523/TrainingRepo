<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 開発・テスト用の既知アカウント。既知の脆弱な認証情報のため本番では作成しない。
        // 本番の初期管理者は `php artisan app:create-admin` で作成すること。
        if (app()->environment('local', 'testing')) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => 'admin',
            ]);
        }

        // フォーム設定はアプリの構成データのため全環境で投入する。
        $this->call(FormFieldSettingSeeder::class);
    }
}
