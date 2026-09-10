<?php
namespace App\Services;

use App\Exceptions\StaleUpdateException;
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

    public function update(ProjectRequest $request, Project $project): Project
    {
        $skills = [
            'required'  => $request->input('required_skills', []),
            'preferred' => $request->input('preferred_skills', []),
        ];

        return DB::transaction(function () use ($request, $project, $skills) {
            $locked = Project::lockForUpdate()->findOrFail($project->id);

            if ($locked->version !== (int) $request->input('version')) {
                throw StaleUpdateException::forVersionMismatch();
            }
            
            // 2026-09-02 修正／レビュー指摘: increment($column, $amount, $extra) による
            // 1回のUPDATEへの統合は、date/datetime キャスト列（本サービスでは start_date）が
            // DB上で書式崩れする不具合があったため取り消した（詳細は EngineerService 参照）。
            $locked->update($this->projectAttributes($request));
            $this->replaceSkills($locked, $skills);
            $locked->increment('version');

            return $locked;
        });
    }

    /**
    * リクエストから Project の保存用属性配列を組み立てる
    */
    private function projectAttributes(ProjectRequest $request): array
    {
        return array_merge(
            $request->safe()->except(['required_skills', 'preferred_skills', 'rate_is_negotiable', 'version']),
            [
                'headcount'        => $request->headcount !== null ? (int) $request->headcount : null,
                'interview_count'  => $request->interview_count !== null ? (int) $request->interview_count : null,
                'main_user_id'     => (int) $request->main_user_id,
                'sub_user_id'      => $request->sub_user_id !== null ? (int) $request->sub_user_id : null,

                // rate_is_negotiable が true の場合：rate_min / rate_max を null にし
                // rate_note が未入力であればデフォルト値をセットする（QA #14確定）
                'rate_min'  => $request->boolean('rate_is_negotiable') ? null : ($request->rate_min  !== null ? (int) $request->rate_min  : null),
                'rate_max'  => $request->boolean('rate_is_negotiable') ? null : ($request->rate_max  !== null ? (int) $request->rate_max  : null),
                // rate_is_negotiable が false の場合：rate_note はUI上も入力欄自体が
                // 表示されないフィールドのため、送信内容に関わらず必ず null にする
                // （フロントで稼働形態切替時に値をクリアしなくなったことで、以前
                // スキル見合いだった際のrate_noteが残ったまま送信されるケースがあるため）
                'rate_note' => $request->boolean('rate_is_negotiable')
                ? ($request->rate_note ?: 'スキル見合い')  // 未入力ならデフォルト値
                : null,

                // work_style が remote の場合：勤務地は必ず null にする（フロント側は
                // 稼働形態切替時に入力値を保持したままにしているため、保存時にサーバー側で
                // 強制的にnull化することを最終的な保証とする）
                'work_location_line'    => $request->work_style === 'remote' ? null : $request->work_location_line,
                'work_location_station' => $request->work_style === 'remote' ? null : $request->work_location_station,
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

    /**
    * 既存のスキルを削除してから新しい内容で登録し直す（更新用）
    */
    private function replaceSkills(Project $project, array $skills): void
    {
        $project->projectSkills()->delete();
        $this->insertSkills($project, $skills);
    }

    public function destroy(Project $project): void
    {
        $project->delete();
    }
}
