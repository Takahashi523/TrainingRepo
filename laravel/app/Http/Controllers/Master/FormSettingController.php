<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFormSettingRequest;
use App\Services\FormSettingService;
use Illuminate\Http\RedirectResponse;

/**
 * マスタ管理：フォーム設定（必須/任意）の更新。
 * 即時反映のため通常は1件だが settings[] を 1〜N 件受け付ける（UpdateFormSettingRequest）。
 */
class FormSettingController extends Controller
{
    public function __construct(private readonly FormSettingService $formSettingService) {}

    public function update(UpdateFormSettingRequest $request): RedirectResponse
    {
        $this->formSettingService->update($request->validated()['settings'], $request->user()->id);

        // 即時反映トグルはフラッシュ（トースト）を出さない。
        // 連続操作でトーストが頻発して煩わしいため、確認は画面側の行内フィードバックに委ねる。
        return redirect()->route('master.index');
    }
}
