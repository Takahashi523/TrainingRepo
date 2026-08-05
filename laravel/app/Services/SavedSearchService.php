<?php

namespace App\Services;

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
            $sanitized = [
                'status' => array_values(array_intersect(
                    (array) ($conditions['status'] ?? []),
                    array_column(Engineer::STATUSES, 'key')
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
                'sort' => (string) ($conditions['sort'] ?? ''),
                'order' => (string) ($conditions['order'] ?? ''),

            ];
        } else {
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
                'sort' => (string) ($conditions['sort'] ?? ''),
                'order' => (string) ($conditions['order'] ?? ''),
            ];
        }

        return $sanitized;
    }
}