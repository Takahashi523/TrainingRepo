import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import RankBadge, { RANK_BAR_STYLES, RANK_BAR_FALLBACK_STYLE } from '@/Components/Common/RankBadge';
import SkillTag from '@/Components/Common/SkillTag';
import TruncatedText from '@/Components/Common/TruncatedText';
import { cn } from '@/lib/utils';
import { MatchResult } from '@/types/matching';
import { Ban, Check } from 'lucide-react';

/** 単価レンジの表示（下限〜上限。無ければ備考、それも無ければ —）。 */
export function formatRate(min: number | null, max: number | null, note: string | null): string {
    if (min != null && max != null) return `${min}万円〜${max}万円`;
    if (min != null) return `${min}万円〜`;
    if (max != null) return `〜${max}万円`;
    return note || '—';
}

interface Props {
    result: MatchResult;
    selected: boolean;
    onSelect: () => void;
}

/**
 * マッチング結果カード（WF_09）。
 * 左：ランク・スコア・スコアバー ／ 右：案件名・メタ・スキルタグ（必須実線/尚可点線/勤務形態薄枠）・工程リスト。
 * D ランクは「見送り推奨」のため opacity を下げる（スコアリングロジック設計書 §3.3）。
 */
export default function MatchCard({ result, selected, onSelect }: Props) {
    const { project, match_score, match_rank, is_in_pipeline, is_available, is_project_full } =
        result;
    const accentClass = RANK_BAR_STYLES[match_rank] ?? RANK_BAR_FALLBACK_STYLE;

    // 工程はサマリーと同じ共通 ProcessCheckboxGroup で表示する（DRY）。案件は is_target フラグ。
    const { phaseList, phaseValues } = buildProcessPhaseProps(project.phases, 'is_target');

    // 単価は下限/上限が無ければ formatRate が備考、それも無ければ '—' を返す。
    // ラベルなしメタでは '—' だと何の項目か伝わらないため、真の未設定（'—'）は「単価未定」に置き換える。
    const rateText = formatRate(project.rate_min, project.rate_max, project.rate_note);

    // スキルは必須→尚可の順に結合。既定は「1 行に収まる分だけ」表示し、あふれた分はトグルで
    // カード内に全件展開する（CollapsibleTagRow が実幅を計測して判定）。マッチ検証（必須スキルを
    // 満たすか）を人がカード内で完結して確認できるようにしつつ、既定はコンパクトに保つ。
    const skills = [
        ...project.required_skills.map((s) => ({ label: s.label, skillType: 'required' as const })),
        ...project.preferred_skills.map((s) => ({ label: s.label, skillType: 'preferred' as const })),
    ];

    return (
        // ルートは <button> ではなく role="button" の <div>。
        // 内部に ProcessCheckboxGroup（Radix Checkbox = <button>）を置くため、button の入れ子を避ける。
        // クリック＝ドロワー展開、Enter/Space でも発火させアクセシビリティを担保する。
        <div
            role="button"
            tabIndex={0}
            onClick={onSelect}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onSelect();
                }
            }}
            className={cn(
                // ホバー時は進捗管理カード（PipelineCard）と同じく背景色を変える（transition-colors hover:bg-muted/50）
                'flex w-full cursor-pointer items-stretch overflow-hidden rounded-md border bg-white text-left transition-colors hover:bg-muted/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                selected ? 'border-primary ring-1 ring-primary/40' : 'border-border',
                // 追加不可（掲載停止 or 上限到達）はカードを淡くして非アクティブを示す（クリックで詳細は開ける）。
                // 「追加済み」は完了状態として淡色化しない。
                !is_in_pipeline && (!is_available || is_project_full) && 'opacity-60',
            )}
        >
            {/* 左端：ランク色アクセントバー（人材一覧カードのステータスバーと同じ役割） */}
            <div className={cn('w-1.5 shrink-0', accentClass)} />

            {/* WF_09 準拠：左を「ランク＋スコア」列と「スコアバー」列の横2列に分け、card-main と縦中央で揃える。
                min-w-0：ネストした flex 内で案件名（下の TruncatedText）を確実に truncate させるため、
                中間の flex 親にも min-width:0 を付ける（これが無いと長い案件名が縮まずバッジを押し出す）。 */}
            <div className="flex min-w-0 flex-1 items-center gap-4 p-4">
                {/* ランク＋スコア列（WF: rank-badge 52px 相当） */}
                <div className="flex w-14 shrink-0 flex-col items-center gap-1">
                    <RankBadge rank={match_rank} className="text-[11px]" />
                    <div className="font-bold leading-none text-foreground">
                        <span className="text-2xl tabular-nums">{match_score}</span>
                        <span className="ml-0.5 text-[10px] text-muted-foreground">点</span>
                    </div>
                </div>

                {/* スコアバー列（WF: score-bar-wrap 80px 相当）。バーはランク配色に揃える */}
                <div className="w-20 shrink-0">
                    <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        {/* 範囲外スコア（0未満/100超）でもバーが溢れ・負幅にならないよう 0〜100 に clamp（MatchDrawer と統一）。 */}
                        <div
                            className={cn('h-full rounded-full', accentClass)}
                            style={{ width: `${Math.min(100, Math.max(0, match_score))}%` }}
                        />
                    </div>
                    <div className="mt-1 text-center text-[9px] text-muted-foreground">{match_score} / 100</div>
                </div>

                {/* 右：案件情報 */}
                <div className="min-w-0 flex-1">
                    <div className="flex items-start gap-2">
                        {/* 案件名は長くなり得るため flex-1+min-w-0 で残り幅を占有しつつ 1 行省略
                            （省略時のみ全文ツールチップ）。これによりバッジ（下の shrink-0）を押し出さず、
                            案件名が長くても「追加済み/掲載停止/上限到達」が常に見えるようにする。 */}
                        <TruncatedText
                            as="p"
                            text={project.name}
                            className="min-w-0 flex-1 text-sm font-bold text-foreground"
                        />
                        {/* 「追加済み」はステータスの分類体系に属さない補助マーカー。
                            ランク・ステータスは“塗りピル”なのに対し、こちらは塗り・枠なしの「✓＋文字」だけにして
                            別カテゴリと一目で分かるようにする（色相での衝突議論から外す）。 */}
                        {/* 追加不可の理由マーカー（優先度：追加済み > 掲載停止 > 上限到達）。
                            いずれも「追加済み」と体裁を揃える（枠なし・アイコン＋文字・muted）。 */}
                        {is_in_pipeline ? (
                            <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-semibold text-muted-foreground">
                                <Check className="h-2.5 w-2.5" />
                                パイプライン追加済み
                            </span>
                        ) : !is_available ? (
                            /* open 以外（closed=終了 / pending=ペンディング）は追加不可。ステータス別ラベル。 */
                            <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-semibold text-muted-foreground">
                                <Ban className="h-2.5 w-2.5" />
                                {project.status_label}
                            </span>
                        ) : (
                            /* 掲載中だが既存パイプラインが上限（5件）到達で追加不可。 */
                            is_project_full && (
                                <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-semibold text-muted-foreground">
                                    <Ban className="h-2.5 w-2.5" />
                                    上限到達
                                </span>
                            )
                        )}
                    </div>

                    {/* メタ：ラベルなしで値のみを横一列に並べる（`｜` 区切り・折返し可）。
                        値ありはフォーマットで項目を自己識別。未指定は横長を避けつつ項目が分かるよう
                        「フィールド名入りトークン」で表示する。案件登録時に既知の属性（クライアント・商流）は
                        未入力を表す「未設定」、後から決まり得る条件（募集人数・開始時期・単価・勤務形態）は「未定」と語を使い分ける。 */}
                    <div className="mt-1 flex flex-wrap items-baseline gap-x-1.5 text-[11px] text-muted-foreground">
                        {/* 顧客名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（max-w で幅を抑える）。 */}
                        <TruncatedText
                            text={project.client_name || 'クライアント未設定'}
                            className="min-w-0 max-w-[12rem]"
                        />
                        <span>｜ {project.commercial_flow_label ?? '商流未設定'}</span>
                        <span>｜ {project.headcount != null ? `${project.headcount}名` : '募集人数未定'}</span>
                        <span>｜ {project.start_date ? project.start_label : '参画開始時期未定'}</span>
                        <span>｜ {rateText === '—' ? '単価未定' : rateText}</span>
                        <span>｜ {project.work_style_label ?? '勤務形態未定'}</span>
                    </div>

                    {/* スキルタグ：必須=実線 / 尚可=点線 / 勤務形態=薄枠。
                        必須・尚可が無い案件でもタグ行が空にならないようプレースホルダを出し、カード高さのばらつきを防ぐ。 */}
                    {skills.length > 0 ? (
                        <CollapsibleTagRow className="mt-1.5">
                            {skills.map((s, i) => (
                                <SkillTag key={`${s.skillType}-${s.label}-${i}`} label={s.label} skillType={s.skillType} className="text-muted-foreground" />
                            ))}
                        </CollapsibleTagRow>
                    ) : (
                        <div className="mt-1.5">
                            <span className="text-[11px] text-muted-foreground">スキル未設定</span>
                        </div>
                    )}

                    {/* 工程リスト（対象工程）。サマリーと同じ共通 ProcessCheckboxGroup を readOnly で使用。
                        サマリーと同じ縮小ラッパーでサイズを揃え、id はカードごとに一意化（idPrefix）して重複を防ぐ。 */}
                    <div className="mt-2 [&_button]:h-3.5 [&_button]:w-3.5 [&_label]:text-[11px] [&_svg]:h-2.5 [&_svg]:w-2.5">
                        <ProcessCheckboxGroup
                            phases={phaseList}
                            values={phaseValues}
                            readOnly
                            idPrefix={`match-${project.id}-`}
                            labelClassName="text-muted-foreground"
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}
