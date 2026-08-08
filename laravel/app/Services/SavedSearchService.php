<?php

namespace App\Services;

use App\Http\Controllers\EngineerController;
use App\Http\Controllers\ProjectController;
use App\Models\Engineer;
use App\Models\Project;
use App\Models\SavedSearch;
use Illuminate\Support\Facades\Auth;

class SavedSearchService
{
    public function store(array $data): SavedSearch
    {
        $conditions = $this->sanitizeConditions($data['search_type'], $data['conditions']);

        return SavedSearch::create([
            'user_id' => Auth::id(),
            'name' => $data['name'],
            'search_type' => $data['search_type'],
            'conditions' => $conditions,
        ]);
    }

    public function delete(SavedSearch $savedSearch): void
    {
        $savedSearch->delete();
    }

    private function sanitizeConditions(string $searchType, array $conditions): array
    {
        if ($searchType === 'engineer') {
            [$sort, $order] = $this->resolveSortPair(
                EngineerController::SORT_OPTIONS,
                (string) ($conditions['sort'] ?? ''),
                (string) ($conditions['order'] ?? '')
            );

            $sanitized = [
                'status' => array_values(array_intersect(
                    (array) ($conditions['status'] ?? []),
                    array_column(Engineer::STATUSES, 'value')
                )),
                'work_styles' => array_values(array_intersect(
                    (array) ($conditions['work_styles'] ?? []),
                    array_column(Engineer::WORK_STYLES, 'key')
                )),
                'phases' => array_values(array_intersect(
                    (array) ($conditions['phases'] ?? []),
                    array_column(Engineer::PHASES, 'key')
                )),
                'keyword' => (string) ($conditions['keyword'] ?? ''),
                'sort' => $sort,
                'order' => $order,

            ];
        } else {
            [$sort, $order] = $this->resolveSortPair(
                ProjectController::SORT_OPTIONS,
                (string) ($conditions['sort'] ?? ''),
                (string) ($conditions['order'] ?? '')
            );

            $sanitized = [
                'status' => array_values(array_intersect(
                    (array) ($conditions['status'] ?? []),
                    array_column(Project::STATUSES, 'value')
                )),
                'work_style' => array_values(array_intersect(
                    (array) ($conditions['work_style'] ?? []),
                    array_column(Project::WORK_STYLES, 'value')
                )),
                'commercial_flow' => array_values(array_intersect(
                    (array) ($conditions['commercial_flow'] ?? []),
                    array_column(Project::COMMERCIAL_FLOWS, 'value')
                )),
                'interview_count' => array_values(array_intersect(
                    (array) ($conditions['interview_count'] ?? []),
                    array_column(Project::INTERVIEW_COUNTS, 'value')
                )),
                'keyword' => (string) ($conditions['keyword'] ?? ''),
                'sort' => $sort,
                'order' => $order,
            ];
        }

        return $sanitized;
    }

    /**
     * sort×order のペアを許可リスト（EngineerController/ProjectController の SORT_OPTIONS。SSOT）
     * と突き合わせて検証する。EngineerController::resolveSort() / ProjectController::resolveSort()
     * と同じ「一致しなければ先頭（デフォルト）へフォールバック」という考え方に合わせている。
     *
     * @param array<int, array{sort: string, order: string, label: string}> $sortOptions
     * @return array{0: string, 1: string} [$sort, $order]
     */
    private function resolveSortPair(array $sortOptions, string $sort, string $order): array
    {
        $order = strtolower($order);

        foreach ($sortOptions as $opt) {
            if ($opt['sort'] === $sort && $opt['order'] === $order) {
                return [$opt['sort'], $opt['order']];
            }
        }

        return [$sortOptions[0]['sort'], $sortOptions[0]['order']];
    }
}