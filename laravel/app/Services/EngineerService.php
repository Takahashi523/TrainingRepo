<?php

namespace App\Services;

use App\Http\Requests\EngineerRequest;
use App\Models\Engineer;
use App\Services\Ai\AiSummaryClient;
use App\Services\Ai\AiSummaryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 人材（engineer）の書き込みロジックを集約するサービス。
 *
 * 案件側の {@see ProjectService} と方式を揃え、Controller を薄く保つ（SRP）。トランザクション境界と
 * スキルの洗い替え、そして人材固有の AI 要約生成トリガーを本サービスに閉じる。
 */
class EngineerService
{
    public function __construct(private readonly AiSummaryClient $aiSummary) {}

    /**
     * 人材を新規登録する。
     *
     * appeal_note に入力がある場合のみ AI 要約を生成する（#12 §5.1 トリガー：人材登録時）。
     */
    public function store(EngineerRequest $request): EngineerWriteResult
    {
        $skills = $request->input('skills', []);

        $engineer = DB::transaction(function () use ($request, $skills) {
            $engineer = Engineer::create($this->engineerAttributes($request));
            $this->insertSkills($engineer, $skills);

            return $engineer;
        });

        // AI 要約は付加情報。DB トランザクション外で呼び、失敗しても登録自体は成功させる。
        $aiSummaryFailed = filled($request->input('appeal_note'))
            ? $this->refreshAiSummary($engineer)
            : false;

        return new EngineerWriteResult($engineer, $aiSummaryFailed);
    }

    /**
     * 人材を更新する。
     *
     * appeal_note が変更された場合のみ AI 要約を再生成する（#12 §5.1 トリガー：appeal_note 更新時）。
     */
    public function update(EngineerRequest $request, Engineer $engineer): EngineerWriteResult
    {
        $previousAppealNote = $engineer->appeal_note;

        DB::transaction(function () use ($request, $engineer) {
            $engineer->update($this->engineerAttributes($request));
            $this->replaceSkills($engineer, $request->input('skills', []));
        });

        $aiSummaryFailed = $request->input('appeal_note') !== $previousAppealNote
            ? $this->refreshAiSummary($engineer)
            : false;

        return new EngineerWriteResult($engineer, $aiSummaryFailed);
    }

    public function destroy(Engineer $engineer): void
    {
        $engineer->delete();
    }

    /**
     * AI 要約（人材プロフィール要約）を生成して engineer に保存する。
     *
     * AI 要約は登録・更新の付加情報のため、上流障害（接続不可・タイムアウト・4xx/5xx）でも本体の保存は
     * 成功として扱い、ここでは Log::warning に留める（#21-🔴4）。空出力（生成対象なし）は失敗ではなく、
     * ai_summary を NULL のまま据え置く（#12 §4.3）。
     *
     * @return bool AI 呼び出しが上流障害で失敗したら true（成功・空出力は false）
     */
    private function refreshAiSummary(Engineer $engineer): bool
    {
        try {
            $result = $this->aiSummary->generate($engineer);
            if ($result !== null) {
                // Python が返す ISO8601 の生成時刻をそのまま保存する。
                $engineer->update([
                    'ai_summary' => $result->summary,
                    'ai_summary_generated_at' => $result->generatedAt,
                ]);
            }

            return false;
        } catch (AiSummaryException $e) {
            Log::warning('AI要約生成に失敗しました', [
                'engineer_id' => $engineer->id,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * リクエストから Engineer の保存用属性配列を組み立てる。
     * work_styles[] → work_style_* 3カラムへ変換する。
     */
    private function engineerAttributes(EngineerRequest $request): array
    {
        $workStyles = $request->input('work_styles', []);

        return array_merge(
            $request->safe()->except(['skills', 'work_styles']),
            [
                'work_style_onsite' => in_array('onsite', $workStyles),
                'work_style_hybrid' => in_array('hybrid', $workStyles),
                'work_style_remote' => in_array('remote', $workStyles),
            ]
        );
    }

    /**
     * @param  array<int, array{label: string|null, detail: string|null}>  $skills
     */
    private function insertSkills(Engineer $engineer, array $skills): void
    {
        // ConvertEmptyStringsToNull ミドルウェア通過後に label が null になった
        // 「空の行」を防御的にスキップする（バリデーション通過した場合でも DB を汚染させない）
        $meaningful = array_filter(
            $skills,
            fn ($s) => ! empty($s['label']) || ! empty($s['detail'])
        );

        if (empty($meaningful)) {
            return;
        }

        $engineer->skills()->createMany(
            array_map(fn ($s) => [
                'label' => $s['label'] ?? null,
                'detail' => $s['detail'] ?? null,
            ], $meaningful)
        );
    }

    /**
     * 既存スキルを全削除して送信内容で再挿入する（API設計書 #3/#6 の全件洗い替え方針）。
     *
     * @param  array<int, array{label: string|null, detail: string|null}>  $skills
     */
    private function replaceSkills(Engineer $engineer, array $skills): void
    {
        $engineer->skills()->delete();
        $this->insertSkills($engineer, $skills);
    }
}
