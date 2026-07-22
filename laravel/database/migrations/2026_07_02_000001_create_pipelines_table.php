<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * パイプライン（人材×案件の進捗）テーブル。
     * DB設計書 §pipelines / §6-4 に準拠。
     * 生成はマッチング画面経由のみ（QA #43）。
     */
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            // 人材・案件が物理削除されたら関連パイプラインも削除（CASCADE）
            $table->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->enum('status', [
                // 進行中12種
                'proposed', 'applied_by_candidate', 'applying',
                'first_scheduling', 'first_waiting', 'first_result_waiting',
                'final_scheduling', 'final_waiting', 'final_result_waiting',
                'offered', 'assign_waiting', 'contracted',
                // 終了4種
                'rejected', 'closed', 'assign_declined', 'declined',
            ])->default('proposed');
            // マッチング生成時スナップショット（本機能では変更しない）
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->char('match_rank', 1)->nullable();
            $table->text('ai_score_reason')->nullable();
            $table->text('ai_comment')->nullable();
            $table->text('ai_missing')->nullable();
            // 管理情報（ドロワーで編集可能）
            $table->text('client_comment')->nullable();
            $table->text('ng_reason')->nullable();
            $table->date('next_action_date')->nullable();
            // 終了ステータスへ遷移したタイミングで記録
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            // 同一人材×同一案件のパイプラインは1件のみ
            $table->unique(['engineer_id', 'project_id'], 'uk_pipelines_engineer_project');
            $table->index('status', 'idx_pipelines_status');
            $table->index('next_action_date', 'idx_pipelines_next_action_date');
            // engineer_id / project_id は foreignId->constrained() でインデックス自動生成
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pipelines');
    }
};
