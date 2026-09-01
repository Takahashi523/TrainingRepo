<?php

namespace App\Services;

use App\Exceptions\StaleUpdateException;
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
                // 上限チェック：進行中（アクティブ）のパイプライン件数のみを数える（QA #50・アクティブ5件）。
                // 終了済み（不成立・見送り等の terminal）は枠を消費しない＝再度追加できる。
                // 同一案件の進行中件数を lockForUpdate で確定させてから判定する。
                $count = Pipeline::where('project_id', $projectId)
                    ->whereIn('status', Pipeline::inProgressValues())
                    ->lockForUpdate()
                    ->count();

                if ($count >= Pipeline::MAX_PER_PROJECT) {
                    throw ValidationException::withMessages([
                        'project_id' => 'この案件のパイプラインはすでに上限（'.Pipeline::MAX_PER_PROJECT.'件）に達しています。',
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
            // 競合で事前チェック（重複・上限）を貫通した DB 制約違反の処理。
            // SQLSTATE で判定しない：`23000` は UNIQUE 違反だけでなく FK 違反も含むため、
            // 案件/人材が計算中〜追加の間に削除された FK 違反を「すでに追加済み」と誤変換してしまう
            // （MySQL 1062 等のドライバ依存コードは SQLite テストで動かない）。
            // 代わりに実際に (engineer_id, project_id) の重複行が存在するかで判定する（ドライバ非依存）。
            $isDuplicate = Pipeline::where('engineer_id', $engineerId)
                ->where('project_id', $projectId)
                ->exists();

            if ($isDuplicate) {
                throw ValidationException::withMessages([
                    'project_id' => 'この人材はすでにこの案件のパイプラインに追加されています。',
                ]);
            }

            // 重複でない制約違反（FK 違反など）は「重複」と偽らずそのまま送出する。
            throw $e;
        }
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

        $version = $data['version'] ?? null;
        unset($data['version']);

        return DB::transaction(function () use ($pipeline, $data, $version) {
            $locked = Pipeline::lockForUpdate()->findOrFail($pipeline->id);

            if ($locked->version !== (int) $version) {
                throw StaleUpdateException::forVersionMismatch();
            }

            if (array_key_exists('status', $data)
                && Pipeline::isTerminal($data['status'])
                && ! Pipeline::isTerminal($locked->status)) {
                $data['ended_at'] = now();
            }

            $locked->update($data);
            $locked->increment('version');

            return $locked;
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
