<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedSearchRequest;
use App\Models\SavedSearch;
use App\Services\SavedSearchService;
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
        $this->authorize('delete', $savedSearch);

        $this->savedSearchService->delete($savedSearch);

        return redirect()->back()->with('success', '検索条件を削除しました。');
    }
}
