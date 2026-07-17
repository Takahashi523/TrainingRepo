<?php

namespace App\Services;

use App\Models\Pipeline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * パイプラインの状態変更ロジックをコントローラから切り出す（単一責任・薄いコントローラ）。
 * バリデーション（終了ロックガード）は PipelineUpdateRequest 側に残し、
 * 本 Service は「検証済みデータの永続化」に責務を限定する。
 */
class PipelineService
{
    /** 1案件あたりのパイプライン追加上限（QA #50・マッチング表示件数と同数） */
    private const MAX_PER_PROJECT = 5;

    /**
     * マッチング結果からパイプラインを新規生成する（POST /pipelines）。
     *
     * 上限（1案件5件）チェック・重複チェック・生成を単一トランザクションで実施し、
     * チェックと INSERT の間に別リクエストが割り込む競合を防ぐ。複合 UNIQUE
     * 制約（uk_pipelines_engineer_project）を最終防波堤とし、競合で貫通した場合も
     * QueryException を重複エラー（422）に変換して一貫した応答にする。
     *
     * @param  array<string, mixed>  $data  PipelineStoreRequest の検証済みデータ
     *
     * @throws ValidationException 重複追加（422）/ 上限超過（422）
     */
    public function create(array $data): Pipeline
    {
        $engineerId = (int) $data['engineer_id'];
        $projectId = (int) $data['project_id'];

        try {
            return DB::transaction(function () use ($data, $engineerId, $projectId) {
                // 上限チェック：同一案件の既存件数を lockForUpdate で確定させてから判定する。
                $count = Pipeline::where('project_id', $projectId)
                    ->lockForUpdate()
                    ->count();

                if ($count >= self::MAX_PER_PROJECT) {
                    throw ValidationException::withMessages([
                        'project_id' => 'この案件のパイプラインはすでに上限（'.self::MAX_PER_PROJECT.'件）に達しています。',
                    ]);
                }

                // 重複チェック：(engineer_id, project_id) の既存有無。
                $exists = Pipeline::where('engineer_id', $engineerId)
                    ->where('project_id', $projectId)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'project_id' => 'この人材はすでにこの案件のパイプラインに追加されています。',
                    ]);
                }

                // status は proposed 固定（QA #49）。スナップショット列のみ保存する。
                return Pipeline::create([
                    'engineer_id' => $engineerId,
                    'project_id' => $projectId,
                    'status' => 'proposed',
                    'match_score' => $data['match_score'],
                    'match_rank' => $data['match_rank'],
                    'ai_score_reason' => $data['ai_score_reason'] ?? null,
                    'ai_comment' => $data['ai_comment'] ?? null,
                    'ai_missing' => $data['ai_missing'] ?? null,
                ]);
            });
        } catch (QueryException $e) {
            // UNIQUE 制約違反（競合で重複チェックを貫通したケース）を重複エラーへ変換する。
            if ($this->isUniqueViolation($e)) {
                throw ValidationException::withMessages([
                    'project_id' => 'この人材はすでにこの案件のパイプラインに追加されています。',
                ]);
            }

            throw $e;
        }
    }

    /**
     * QueryException が UNIQUE 制約違反（SQLSTATE 23000）かどうか。
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000';
    }

    /**
     * パイプラインを部分更新する。
     * 進行中 → 終了ステータスへ遷移した場合は ended_at を now() で記録する。
     * （終了済みパイプラインの更新は PipelineUpdateRequest で一切 422 ブロック済みのため、ここには到達しない）
     *
     * @param  array<string, mixed>  $data  status / client_comment / ng_reason / next_action_date のうち送信されたキー
     */
    public function update(Pipeline $pipeline, array $data): Pipeline
    {
        // status の明示的な null は「ステータス変更なし」として無視する。
        // バリデーション設計書 §6 は status を nullable と定義しているが、DB 上は NOT NULL（ENUM）
        // のため null をそのまま UPDATE に渡すと DB エラー（500）になる。
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }

        return DB::transaction(function () use ($pipeline, $data) {
            if (array_key_exists('status', $data)
                && Pipeline::isTerminal($data['status'])
                && ! Pipeline::isTerminal($pipeline->status)) {
                $data['ended_at'] = now();
            }

            $pipeline->update($data);

            return $pipeline;
        });
    }

    /**
     * パイプラインを物理削除する（認可はコントローラ側の Policy で担保）。
     */
    public function delete(Pipeline $pipeline): void
    {
        $pipeline->delete();
    }
}
