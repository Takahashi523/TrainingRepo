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
                // CSV インポート成功サマリ（{resource, summary:{total_rows, created, updated}}）。
                // 成功時のみ redirect back で load され、フロントは onSuccess でトースト表示に使う。
                // 失敗（ファイル/行エラー）は flash では返さない（onSuccess 誤発火防止）＝422 の
                // errors.importErrors（JSON 文字列）で返す。
                'importResult' => fn () => session('importResult'),
                // issue #61 課題4：CSVインポート経由のAI要約一括生成が時間予算超過で一部スキップ
                // されたときの警告（{triggered, skipped}）。skipped > 0 のときのみ flash される。
                // 【不具合修正】コントローラで session()->flash('aiSummarySkipped', ...) していたが、
                // ここの 'flash' 配列にキーを追加し忘れておりフロントに渡っていなかった（画面上は
                // 成功バナーのみでスキップ警告が一切出ない不具合として手動確認で発覚）。
                'aiSummarySkipped' => fn () => session('aiSummarySkipped'),
            ],
        ];
    }
}
