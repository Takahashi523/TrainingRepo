import RankBadge from '@/Components/Common/RankBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import { RANK_ACCENT, RANK_ACCENT_FALLBACK } from '@/Components/Matching/MatchCard';
import { Button } from '@/Components/ui/button';
import { MatchResult } from '@/types/matching';
import { useForm } from '@inertiajs/react';
import { Check, Plus, X } from 'lucide-react';

interface Props {
    result: MatchResult;
    engineerId: number;
    onClose: () => void;
}

/**
 * ドロワー内の AI テキストブロック。
 * 進捗管理ドロワー（PipelineDrawer）の AI 折りたたみ（AiAccordion）と同じ体裁
 * （AI バッジ・淡色ヘッダ・枠付き・白い本文）だが、マッチング結果では判断材料を一目で見せたいため
 * 常時展開（アコーディオンなし）で表示する。
 */
function AiBlock({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="shrink-0 overflow-hidden rounded border border-border">
            <div className="flex items-center gap-1.5 bg-muted/60 px-3 py-1.5 text-[11px] font-semibold text-muted-foreground">
                {/* AI バッジ色は PipelineDrawer・人材詳細と統一 */}
                <span className="rounded-sm bg-purple-600 px-1.5 py-px text-[9px] font-bold text-white">AI</span>
                {title}
            </div>
            <div className="bg-white p-2.5">{children}</div>
        </div>
    );
}

/**
 * マッチング結果ドロワー（WF_09）。見せ方は進捗管理ドロワー（PipelineDrawer）に合わせる。
 * ヘッダー（案件名）／スコアサマリ箱／AIスコア算出理由／AI総合コメント（推薦理由＋不足条件）／
 * フッター（＋パイプラインに追加）で構成する。AI 表示は PipelineDrawer と異なりアコーディオンにしない。
 *
 * 追加はスナップショット（マッチング実行時点の値）を POST /pipelines し、成功時はリダイレクトで props 更新＋成功トースト。
 * オーバーレイ・固定パネルは呼び出し元（Pages/Matching/Show）が持つため、ここは中身のみ（h-full の flex カラム）。
 */
export default function MatchDrawer({ result, engineerId, onClose }: Props) {
    const { project } = result;
    const score = result.match_score;
    // スコアバー色はカード（MatchCard）と同じランク配色を共有し、カード→ドロワーで色が変わらないようにする。
    const accentClass = RANK_ACCENT[result.match_rank] ?? RANK_ACCENT_FALLBACK;

    // スナップショット（マッチング実行時点の値）をそのまま送信する（サーバーで再計算しない）。
    const form = useForm({
        engineer_id: engineerId,
        project_id: project.id,
        match_score: result.match_score,
        match_rank: result.match_rank,
        ai_score_reason: result.ai_score_reason,
        ai_comment: result.ai_comment,
        ai_missing: result.ai_missing,
    });

    const handleAdd = () => {
        // 成功（2xx）時のみドロワーを閉じる。422（重複・上限超過）は onSuccess が発火しないため、
        // ドロワーは開いたままエラー表示（form.errors.project_id）を保持する。
        form.post('/pipelines', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    const added = result.is_in_pipeline;

    return (
        <div className="flex h-full w-full flex-col">
            <div className="flex shrink-0 items-center justify-between border-b border-border p-4">
                <div className="min-w-0">
                    {/* 案件名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ。 */}
                    <TruncatedText
                        as="p"
                        text={project.name}
                        className="text-[15px] font-bold text-foreground"
                    />
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={onClose}
                    className="ml-2 h-7 w-7 shrink-0 text-muted-foreground [&_svg]:size-3.5"
                    aria-label="閉じる"
                >
                    <X />
                </Button>
            </div>

            {/* ボディ */}
            <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
                {/* スコアサマリ（PipelineDrawer と同じ箱） */}
                <div>
                    <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        マッチングスコア
                    </p>
                    <div className="flex items-center gap-3.5 rounded border border-border bg-muted/40 p-3.5">
                        <div className="flex shrink-0 flex-col items-center gap-1">
                            <RankBadge rank={result.match_rank} className="text-xs" />
                            <div className="text-3xl font-bold leading-none text-foreground">
                                {score}
                                <span className="text-xs font-normal text-muted-foreground"> 点</span>
                            </div>
                        </div>
                        <div className="flex-1">
                            <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={`h-full rounded-full ${accentClass}`}
                                    style={{ width: `${Math.min(100, Math.max(0, score))}%` }}
                                />
                            </div>
                            <p className="mt-1 text-right text-[10px] text-muted-foreground">{score} / 100点</p>
                        </div>
                    </div>

                    {/* AI スコア算出理由（PipelineDrawer と同じ体裁・ただしアコーディオンなしで常時展開） */}
                    <div className="mt-2">
                        <AiBlock title="スコア算出理由">
                            {result.ai_score_reason ? (
                                <p className="whitespace-pre-wrap text-xs leading-relaxed text-foreground">
                                    {result.ai_score_reason}
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground">—</p>
                            )}
                        </AiBlock>
                    </div>
                    <p className="mt-2 border-t border-border pt-2 text-[10px] leading-relaxed text-muted-foreground">
                        ※ スコアはAIによる総合評価です。最終的な提案可否は営業担当者が判断してください。
                    </p>
                </div>

                {/* AI 総合コメント（推薦理由＋不足条件・アコーディオンなし）。
                    両方 null でも節ごと非表示にせず、未生成プレースホルダを出して Silent Rejection を避ける。 */}
                <AiBlock title="総合コメント">
                    <p className="mb-1 text-[11px] font-bold text-muted-foreground">推薦理由</p>
                    {result.ai_comment ? (
                        <p className="whitespace-pre-wrap text-xs leading-relaxed text-foreground">
                            {result.ai_comment}
                        </p>
                    ) : (
                        <p className="text-xs text-muted-foreground">AI推薦理由は未生成です</p>
                    )}
                    <div className="my-2 h-px bg-border" />
                    <p className="mb-1 text-[11px] font-bold text-destructive">⚠ 不足条件</p>
                    {result.ai_missing ? (
                        <p className="whitespace-pre-wrap text-xs leading-relaxed text-muted-foreground">
                            {result.ai_missing}
                        </p>
                    ) : (
                        <p className="text-xs text-muted-foreground">不足条件の指摘はありません</p>
                    )}
                </AiBlock>

                {/* 追加失敗（422）の全エラーを表示する。project_id だけでなく engineer_id も対象。
                    例：計算中〜表示中に対象人材や案件が削除されると exists 検証で弾かれるが、特定フィールドだけを
                    出していると別フィールドのエラーが握りつぶされ Silent Rejection になるため、全 errors を出す。 */}
                {Object.keys(form.errors).length > 0 && (
                    <div className="space-y-1">
                        {(Object.values(form.errors) as string[]).map((message, i) => (
                            <p key={i} className="text-sm font-semibold text-destructive">
                                {message}
                            </p>
                        ))}
                    </div>
                )}
            </div>

            {/* フッタ（PipelineDrawer と同じ体裁：淡色帯＋主ボタン） */}
            <div className="flex shrink-0 items-center gap-2 border-t border-border bg-muted/40 p-4">
                {added ? (
                    <Button className="h-9 flex-1" disabled>
                        <Check className="mr-1.5 h-3.5 w-3.5" />
                        パイプライン追加済み
                    </Button>
                ) : (
                    <Button className="h-9 flex-1" onClick={handleAdd} disabled={form.processing}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" />
                        {form.processing ? '追加中...' : 'パイプラインに追加'}
                    </Button>
                )}
            </div>
        </div>
    );
}
