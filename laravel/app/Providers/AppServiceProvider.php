<?php

namespace App\Providers;

use App\Listeners\UpdateLastLoginAt;
use App\Services\Matching\HttpMatchingEngineClient;
use App\Services\Matching\MatchingEngineClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // マッチングエンジン連携の差し替え点はここ1箇所に集約する（DIP）。
        // 通信手段の変更・テスト時の差し替えはこのバインドを変えるだけで済む。
        $this->app->bind(MatchingEngineClient::class, HttpMatchingEngineClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Event::listen(Login::class, UpdateLastLoginAt::class);
    }
}
