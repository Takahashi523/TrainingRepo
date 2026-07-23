<?php
namespace App\Services;

use App\Http\Requests\ProjectRequest;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function store(ProjectRequest $request): Project
    {
        $skills = [
            'required'  => $request->input('required_skills', []),
            'preferred' => $request->input('preferred_skills', []),
        ];

        return DB::transaction(function () use ($request, $skills) {
            $project = Project::create($this->projectAttributes($request));
            $this->insertSkills($project, $skills);
            return $project;
        });
    }

    /**
    * リクエストから Project の保存用属性配列を組み立てる
    */
    private function projectAttributes(ProjectRequest $request): array
    {
        return array_merge(
            $request->safe()->except(['required_skills', 'preferred_skills', 'rate_is_negotiable']),
            [
                'headcount'        => $request->headcount !== null ? (int) $request->headcount : null,
                'interview_count'  => $request->interview_count !== null ? (int) $request->interview_count : null,
                'main_user_id'     => (int) $request->main_user_id,
                'sub_user_id'      => $request->sub_user_id !== null ? (int) $request->sub_user_id : null,

                // rate_is_negotiable が true の場合：rate_min / rate_max を null にし
                // rate_note が未入力であればデフォルト値をセットする（QA #14確定）
                'rate_min'  => $request->boolean('rate_is_negotiable') ? null : ($request->rate_min  !== null ? (int) $request->rate_min  : null),
                'rate_max'  => $request->boolean('rate_is_negotiable') ? null : ($request->rate_max  !== null ? (int) $request->rate_max  : null),
                'rate_note' => $request->boolean('rate_is_negotiable')
                ? ($request->rate_note ?: 'スキル見合い')  // 未入力ならデフォルト値
                : $request->rate_note,
            ]
        );
    }

    /**
    * スキルを project_skills に保存する
    */
    private function insertSkills(Project $project, array $skills): void
    {
        foreach (['required', 'preferred'] as $type) {
            if (empty($skills[$type])) {
                continue;
            }

            $meaningful = array_filter(
                $skills[$type],
                fn($s) => !empty($s['label']) || !empty($s['detail'])
            );

            if (empty($meaningful)) {
                continue;
            }

            $project->projectSkills()->createMany(
                array_map(fn($s) => [
                    'skill_type' => $type,
                    'label'      => $s['label']  ?? null,
                    'detail'     => $s['detail'] ?? null,
                ], $meaningful)
            );
        }
    }

    public function destroy(Project $project): void
    {
        $project->delete();
    }
}
