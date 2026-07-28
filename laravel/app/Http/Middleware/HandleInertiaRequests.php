<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'role'),
            ],
            // フラッシュは必ずクロージャ（遅延評価）にする。Inertia の share() はコントローラより
            // 前に実行されるため、即時評価だと「同一リクエスト内で flash した値（例：マッチング結果を
            // GET 描画するのと同じリクエストで session()->flash('error', ...) する場合）」を取りこぼす。
            // クロージャなら応答生成時（コントローラ実行後）に解決されるため、リダイレクト経由・同一
            // リクエスト GET の双方で確実に拾える。
            'flash' => [
                'success' => fn () => session('success'),
                'error' => fn () => session('error'),
            ],
        ];
    }
}
