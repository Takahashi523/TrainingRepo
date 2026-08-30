<?php

use App\Models\Engineer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 人材プロフィール要約（ai_summary）の状態管理カラムを追加する（issue #61）。
 *
 * - ai_summary_status：直近の生成トリガーの結果を保持する。許容値は Engineer::AI_SUMMARY_STATUSES（SSOT）。
 *     none      … 未生成（appeal_note が空、または一度も生成トリガーが発生していない）
 *     generated … 直近の生成が成功し、ai_summary が最新の appeal_note に対応している
 *     failed    … 直近の生成が上流障害（接続不可・タイムアウト・4xx/5xx）で失敗した（ai_summary は据え置き）
 *     empty     … 直近の生成は実行されたが、AI が空出力（要約対象なし）を返した（ai_summary は NULL のまま）
 * - ai_summary_source_hash：generated 確定時点の appeal_note の sha256 ハッシュ。
 *     現在の appeal_note のハッシュと一致しない場合、表示中の ai_summary が古い（stale）と判定できる。
 *     generated 以外への遷移では更新しない。
 *
 * ENUM の許容値は Engineer::AI_SUMMARY_STATUSES から生成する（enum('status', [...]) をこのファイルと
 * モデルの2箇所に手打ちで複製しない。pipelines テーブルの status ENUM で同種の重複が指摘されたのを踏まえた
 * 対応）。将来値を追加・変更する際は、モデル定数を直してから ALTER 用の新しいマイグレーションを書けば、
 * その ENUM 定義も同じ生成方法で自動的に追従する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->enum('ai_summary_status', array_column(Engineer::AI_SUMMARY_STATUSES, 'value'))
                ->default('none')
                ->after('ai_summary_generated_at');
            $table->string('ai_summary_source_hash', 64)->nullable()->after('ai_summary_status');
        });

        // 既存データの後方互換バックフィル。
        // 追加前から ai_summary を持つ人材（過去の生成成功分）は、そのままだと DEFAULT 'none' になり
        // 「未生成」として誤表示されてしまう。ai_summary が既にある行は generated として確定させ、
        // source_hash も現在の appeal_note から計算しておく（以後の stale 判定を初回から正しく機能させる）。
        // 行ごとに appeal_note が異なり単一の UPDATE 文では計算できないため、chunkById で1行ずつ
        // PHP 側の hash('sha256', ...) を使って更新する（Engineer::isAiSummaryStale／
        // EngineerService::refreshAiSummary と同じ計算方法）。MySQL の SHA2() 関数を DB::raw() 経由で
        // 使う実装も検討したが、SHA2() は MySQL 専用関数でテスト環境（sqlite）には存在せず、
        // 全テストがマイグレーション失敗で壊れるため採用しない。appeal_note が NULL の場合は
        // 空文字列として扱う。
        DB::table('engineers')
            ->whereNotNull('ai_summary')
            ->orderBy('id')
            ->chunkById(500, function ($engineers): void {
                foreach ($engineers as $engineer) {
                    DB::table('engineers')
                        ->where('id', $engineer->id)
                        ->update([
                            'ai_summary_status' => 'generated',
                            'ai_summary_source_hash' => hash('sha256', (string) $engineer->appeal_note),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('engineers', function (Blueprint $table) {
            $table->dropColumn(['ai_summary_status', 'ai_summary_source_hash']);
        });
    }
};
