<?php

namespace App\Services;

use App\Http\Requests\EngineerRequest;
use App\Models\Engineer;
use App\Services\Ai\AiSummaryClient;
use App\Services\Ai\AiSummaryException;
use Illuminate\Support\Carbon;
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
        // appeal_note が空欄の場合は ai_summary_status は既定値 none のまま（issue #61）。
        $aiSummaryFailed = filled($request->input('appeal_note'))
            ? $this->refreshAiSummary($engineer)
            : false;

        return new EngineerWriteResult($engineer, $aiSummaryFailed);
    }

    /**
     * 人材を更新する。
     *
     * appeal_note が変更された場合のみ AI 要約を再生成する（#12 §5.1 トリガー：appeal_note 更新時）。
     * issue #61：appeal_note が空欄に変更された場合は生成しようがないため、要約自体を未生成（none）へ
     * クリアする（古い appeal_note に基づく要約を残さない）。
     */
    public function update(EngineerRequest $request, Engineer $engineer): EngineerWriteResult
    {
        $previousAppealNote = $engineer->appeal_note;
        $newAppealNote = $request->input('appeal_note');

        DB::transaction(function () use ($request, $engineer) {
            $engineer->update($this->engineerAttributes($request));
            $this->replaceSkills($engineer, $request->input('skills', []));
        });

        $aiSummaryFailed = false;

        if ($newAppealNote !== $previousAppealNote) {
            if (filled($newAppealNote)) {
                $aiSummaryFailed = $this->refreshAiSummary($engineer);
            } else {
                $this->clearAiSummary($engineer);
            }
        }

        return new EngineerWriteResult($engineer, $aiSummaryFailed);
    }

    /**
     * 詳細画面からの明示的な再生成（issue #61 課題2：失敗後のリカバリ手段）。
     *
     * appeal_note の変更有無に依存しない。appeal_note が空欄の場合は生成対象がないため、
     * 要約を未生成（none）にクリアするに留める（AI は呼ばない）。
     *
     * @return bool AI 呼び出しが上流障害で失敗したら true
     */
    public function regenerateAiSummary(Engineer $engineer): bool
    {
        if (blank($engineer->appeal_note)) {
            $this->clearAiSummary($engineer);

            return false;
        }

        return $this->refreshAiSummary($engineer);
    }

    public function destroy(Engineer $engineer): void
    {
        $engineer->delete();
    }

    /**
     * CSV インポート1回分（PR #58）に限定して、appeal_note が入った人材のうち AI 要約が未試行（none）
     * のものへ生成をトリガーする（issue #61 課題4：生成トリガーの全経路適用）。
     *
     * CsvImportService は upsert によるバッチ書き込みのため Eloquent イベントを通らない。そこで
     * CsvImportService::write() が返す「更新行の id 一覧」と「このバッチの created_at/updated_at 基準
     * 時刻」を呼び出し側（CsvController）から受け取り、対象を "今回のインポートで書き込まれた行" だけに
     * 限定する。過去に取り込まれ未試行のまま残っている無関係な既存データはここでは対象にしない
     * （テーブル全体を都度スイープすると、今回インポートしていない行まで生成が走ってしまうため）。
     *
     * 【打ち切り方式：経過時間ベース（固定件数ではない）】
     * AI 呼び出しは同期・直列（1件あたり最大30秒）で、キュー実行基盤（ワーカー）が現状の環境に無い。
     * CSV読込・検証・バッチ書き込み自体で最大十数秒を使う想定（08_CSV入出力_APIエンドポイント一覧.md
     * O-13）のため、PHP の max_execution_time（既定30秒）に対する残り時間は「今回のインポートが書き込みに
     * 何秒使ったか」次第で大きく変わる。固定件数の上限では、この変動に対応できない
     * （小規模インポートでは残り時間があるのに早く打ち切りすぎ、大規模インポートでは書き込みで
     * 時間を使い切った後もタイムアウトの危険が残ったまま同じ件数を回そうとしてしまう）。
     * そこで $importStartedAt（インポート処理開始時刻）からの経過時間を都度計測し、
     * config('services.ai_summary.csv_trigger_budget_seconds')（既定20秒）を超えたら、
     * それ以降は新規のAI呼び出しを行わずスキップする。個々の呼び出し自体のタイムアウト
     * （HttpAiSummaryClient・既定30秒）とは別の、ループ全体に対する累計時間の予算である。
     * 超過分は「スキップ」として件数のみ返し、呼び出し側で flash.error 等により利用者に
     * 個別再生成（WF_05 再生成ボタン）を促す。
     *
     * @param  array<int, int>  $updatedIds  既存行（id 指定で upsert された）の id 一覧。新規行はここに含まれない。
     * @param  \Illuminate\Support\Carbon  $writtenAt  このバッチの created_at/updated_at に使われた基準時刻。新規行の特定に使う。
     * @param  float  $importStartedAt  インポート処理開始時刻（microtime(true)）。経過時間予算の起点。
     * @return array{triggered: int, skipped: int}
     */
    public function triggerAiSummaryForCsvImport(array $updatedIds, Carbon $writtenAt, float $importStartedAt): array
    {
        $pending = Engineer::query()
            ->where('ai_summary_status', 'none')
            ->whereNotNull('appeal_note')
            ->where('appeal_note', '!=', '')
            ->where(function ($query) use ($updatedIds, $writtenAt): void {
                // 新規行：upsert が id を返さないため created_at（このバッチの基準時刻）で特定する。
                $query->where('created_at', $writtenAt->format('Y-m-d H:i:s'));

                // 更新行：id が判明しているのでそのまま絞り込む。
                if ($updatedIds !== []) {
                    $query->orWhereIn('id', $updatedIds);
                }
            })
            ->orderBy('id')
            ->get();

        $budgetSeconds = (float) config('services.ai_summary.csv_trigger_budget_seconds', 20);
        $triggered = 0;
        $skipped = 0;

        foreach ($pending as $engineer) {
            if ((microtime(true) - $importStartedAt) >= $budgetSeconds) {
                // 残り予算を使い切った。以降は呼び出さずスキップとして数えるのみ（HTTP は発生しない）。
                $skipped++;

                continue;
            }

            $this->refreshAiSummary($engineer);
            $triggered++;
        }

        return [
            'triggered' => $triggered,
            'skipped' => $skipped,
        ];
    }

    /**
     * AI 要約（人材プロフィール要約）を生成して engineer に保存する。
     *
     * AI 要約は登録・更新の付加情報のため、上流障害（接続不可・タイムアウト・4xx/5xx）でも本体の保存は
     * 成功として扱い、ここでは Log::warning に留める（#21-🔴4）。空出力（生成対象なし）は失敗ではなく、
     * ai_summary を NULL のまま据え置く（#12 §4.3）。
     *
     * issue #61：結果に応じて ai_summary_status を確定させる。
     *   成功（空でない要約） → generated。ai_summary_source_hash を生成元 appeal_note のハッシュで更新
     *   成功（空出力）       → empty。ai_summary は NULL にクリアする（古い appeal_note の要約を残さない）
     *   上流障害             → failed。ai_summary・ハッシュは直前の値のまま据え置く（stale 判定に利用）
     *
     * @return bool AI 呼び出しが上流障害で失敗したら true（成功・空出力は false）
     */
    private function refreshAiSummary(Engineer $engineer): bool
    {
        try {
            $result = $this->aiSummary->generate($engineer);
            if ($result !== null) {
                // Python が返す ISO8601 の生成時刻を採用する。Engineer 側の datetime キャストが Carbon へ
                // 正規化するため、+09:00 / Z / オフセット無しのいずれでも安全に保存できる。
                $engineer->update([
                    'ai_summary' => $result->summary,
                    'ai_summary_generated_at' => $result->generatedAt,
                    'ai_summary_status' => 'generated',
                    'ai_summary_source_hash' => hash('sha256', (string) $engineer->appeal_note),
                ]);
            } else {
                // 空出力（要約対象なし）。失敗ではないが、現在の appeal_note からは要約が得られなかった
                // という意味なので、古い appeal_note に基づく ai_summary を残さないようクリアする
                // （据え置くと「要約対象なし」なのに古い要約文が表示され続ける矛盾状態になるため）。
                $engineer->update([
                    'ai_summary' => null,
                    'ai_summary_generated_at' => null,
                    'ai_summary_status' => 'empty',
                    'ai_summary_source_hash' => null,
                ]);
            }

            return false;
        } catch (AiSummaryException $e) {
            Log::warning('AI要約生成に失敗しました', [
                'engineer_id' => $engineer->id,
                'error' => $e->getMessage(),
            ]);

            $engineer->update(['ai_summary_status' => 'failed']);

            return true;
        }
    }

    /**
     * AI 要約を未生成（none）状態へクリアする（issue #61）。
     * appeal_note が空欄になった場合など、生成元データが無くなったときに古い要約を残さないために使う。
     */
    private function clearAiSummary(Engineer $engineer): void
    {
        $engineer->update([
            'ai_summary' => null,
            'ai_summary_generated_at' => null,
            'ai_summary_status' => 'none',
            'ai_summary_source_hash' => null,
        ]);
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
