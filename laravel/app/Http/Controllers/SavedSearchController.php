<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedSearchRequest;
use App\Models\SavedSearch;
use App\Services\SavedSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class SavedSearchController extends Controller
{
    public function __construct(private SavedSearchService $savedSearchService) {}

    public function store(SavedSearchRequest $request): RedirectResponse
    {
        $this->savedSearchService->store($request->validated());

        return redirect()->back()->with('success', '検索条件を保存しました。');
    }

    public function destroy(SavedSearch $savedSearch): RedirectResponse
    {
        // 認可はコントローラに残す（人材側 EngineerController::destroy と同様）。権限不足時は 403 を
        // 素で投げず、前画面（保存済み条件は専用画面を持たず一覧画面のモーダルから呼ばれるため、
        // 実質的には人材一覧・案件一覧のいずれか）へ戻し flash.error を返す（issue #94）。
        try {
            $this->authorize('delete', $savedSearch);
        } catch (AuthorizationException) {
            // referer が無い場合（直接リクエストされた場合等）でも flash が失われないよう、
            // ダッシュボードへのfallbackを明示する（#78 TokenMismatchInertiaRedirectorと同方針）。
            return back(fallback: route('dashboard'))->with('error', '削除権限がありません。');
        }

        $this->savedSearchService->delete($savedSearch);

        return redirect()->back()->with('success', '検索条件を削除しました。');
    }
}
