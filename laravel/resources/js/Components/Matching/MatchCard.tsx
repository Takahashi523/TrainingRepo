import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import RankBadge, { RANK_BAR_STYLES, RANK_BAR_FALLBACK_STYLE } from '@/Components/Common/RankBadge';
import Rate from '@/Components/Common/Rate';
import SkillTag from '@/Components/Common/SkillTag';
import TruncatedText from '@/Components/Common/TruncatedText';
import { emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { MatchResult } from '@/types/matching';
import { Ban, Check } from 'lucide-react';

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
                // ホバーは進捗管理カード（PipelineCard）と同じ表現に揃える：淡いプライマリの塗り＋枠線＋影。
                // グレーの塗りはカンバン列などグレー地の上で背景と同化するため、色相を変えて示す。
                'flex w-full cursor-pointer items-stretch overflow-hidden rounded-md border bg-white text-left transition hover:border-primary/50 hover:bg-primary/5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
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
                                <Check aria-hidden="true" className="h-2.5 w-2.5" />
                                パイプライン追加済み
                            </span>
                        ) : !is_available ? (
                            /* open 以外（closed=終了 / pending=ペンディング）は追加不可。ステータス別ラベル。 */
                            <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-semibold text-muted-foreground">
                                <Ban aria-hidden="true" className="h-2.5 w-2.5" />
                                {project.status_label}
                            </span>
                        ) : (
                            /* 掲載中だが既存パイプラインが上限（5件）到達で追加不可。 */
                            is_project_full && (
                                <span className="inline-flex shrink-0 items-center gap-0.5 text-[10px] font-semibold text-muted-foreground">
                                    <Ban aria-hidden="true" className="h-2.5 w-2.5" />
                                    上限到達
                                </span>
                            )
                        )}
                    </div>

                    {/* メタ（表示規約の型2）：ラベル語は出さず値のみを横一列に並べる（`｜` 区切り・折返し可）。
                        値ありはフォーマットで項目を自己識別し、項目名は MetaItem が sr-only で支援技術に渡す。
                        未指定は横長を避けつつ項目が分かるよう「項目名入りトークン」で表示する。案件登録時に
                        既知の属性（クライアント・商流）は「未設定」、後から決まり得る条件（募集人数・開始時期・
                        単価・勤務形態）は「未定」と語を使い分ける（emptyValue.ts が語彙の SSOT）。 */}
                    <MetaRow>
                        <MetaItem field="clientName" valueHasFieldName={!project.client_name}>
                            {/* 顧客名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（max-w で幅を抑える）。 */}
                            <TruncatedText
                                text={project.client_name || emptyText('clientName', true)}
                                className="min-w-0 max-w-[12rem]"
                            />
                        </MetaItem>
                        <MetaItem
                            field="commercialFlow"
                            valueHasFieldName={project.commercial_flow_label == null}
                        >
                            {project.commercial_flow_label ?? emptyText('commercialFlow', true)}
                        </MetaItem>
                        <MetaItem field="headcount" valueHasFieldName={project.headcount == null}>
                            {project.headcount != null ? `${project.headcount}名` : emptyText('headcount', true)}
                        </MetaItem>
                        <MetaItem field="startDate" valueHasFieldName={!project.start_date}>
                            {project.start_date ? project.start_label : emptyText('startDate', true)}
                        </MetaItem>
                        {/* 単価は Rate が withFieldName で項目名入りトークンを出すため、
                            レンジ・備考がすべて空のときだけ sr-only を省く（Rate の欠損分岐と同じ条件）。 */}
                        <MetaItem
                            field="rate"
                            valueHasFieldName={
                                project.rate_min == null && project.rate_max == null && !project.rate_note
                            }
                        >
                            <Rate
                                min={project.rate_min}
                                max={project.rate_max}
                                note={project.rate_note}
                                variant="plain"
                                withFieldName
                            />
                        </MetaItem>
                        <MetaItem field="workStyle" valueHasFieldName={project.work_style_label == null}>
                            {project.work_style_label ?? emptyText('workStyle', true)}
                        </MetaItem>
                    </MetaRow>

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
                            <span className="text-[11px] text-muted-foreground">{emptyText('skills', true)}</span>
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
