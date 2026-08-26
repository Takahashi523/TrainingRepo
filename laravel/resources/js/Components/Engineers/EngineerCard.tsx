import EmptyValue from '@/Components/Common/EmptyValue';
import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import FieldRow from '@/Components/Common/FieldRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import StatusBadge, { STATUS_STYLES } from '@/Components/Common/StatusBadge';
import SkillTag from '@/Components/Common/SkillTag';
import UserAvatar from '@/Components/Common/UserAvatar';
import { Button } from '@/Components/ui/button';
import { emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { EngineerListItem } from '@/types/engineer';
import { router } from '@inertiajs/react';
import { ArrowLeftRight, Clock } from 'lucide-react';

interface Props {
    engineer: EngineerListItem;
    /**
     * 「マッチング」ボタン押下時のハンドラ。
     * マッチング実行は AI 同期計算で数秒待つため、計算中オーバーレイを一覧ページ側で一元管理する。
     * カードは起動のみを担い、遷移・オーバーレイ制御は呼び出し側（Pages/Engineers/Index）に委ねる（9-5）。
     */
    onMatch: () => void;
}

export default function EngineerCard({ engineer, onMatch }: Props) {
    const accentClass = STATUS_STYLES[engineer.status]?.accentClass ?? 'bg-gray-400';

    // スキルはマッチング結果カード・案件一覧カードと同じ CollapsibleTagRow で表示する
    // （固定件数で切らず、実幅で「1行に収まる分」を判定してあふれた分はトグルで展開する）。

    // 工程経験は他画面（人材詳細・マッチング）と同じ共通アダプタで変換する（人材は has_experience）。
    const { phaseList, phaseValues } = buildProcessPhaseProps(engineer.phases, 'has_experience');

    const updatedAt = new Date(engineer.updated_at).toLocaleDateString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });

    return (
        <div className="mb-4 overflow-hidden rounded-md border border-border bg-white">
            <div className="flex items-stretch">
                <div className={cn('w-1.5 shrink-0', accentClass)} />

                <div className="min-w-0 flex-1 p-4">
                    {/* 上段：氏名 + バッジ群 */}
                    <div className="flex flex-wrap items-start gap-3">
                        <div className="min-w-0">
                            <p className="text-base font-bold text-foreground">{engineer.name}</p>
                            {/* 見出し直下メタ（表示規約の型2）：ラベル語は出さず、項目名は sr-only で支援技術に渡す。
                                値が無いときもセグメントごと消さず、項目名入りトークンで「未設定である」ことを見せる
                                （消すと読み落としと区別できないため）。マッチングサマリーと同じ表現。 */}
                            <MetaRow className="mt-0.5">
                                <MetaItem field="age" valueHasFieldName={engineer.age == null}>
                                    {engineer.age != null ? `${engineer.age}歳` : emptyText('age', true)}
                                </MetaItem>
                                {/* 最寄駅が入っていれば値が先頭に来るため sr-only の項目名は必要
                                    （路線だけ欠けている場合も「東京駅（路線未設定）」となり二重読みにならない）。 */}
                                <MetaItem
                                    field="nearestStation"
                                    valueHasFieldName={!engineer.nearest_station}
                                >
                                    {engineer.nearest_station || emptyText('nearestStation', true)}
                                    {engineer.nearest_line
                                        ? `（${engineer.nearest_line}）`
                                        : `（${emptyText('nearestLine', true)}）`}
                                </MetaItem>
                            </MetaRow>
                        </div>
                        {/* 右上：属性バッジと担当／サブのアバターを1行に並べる
                            （アバターは幅が固定なのでバッジを押し出さない）。 */}
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <StatusBadge status={engineer.status} />
                            <span className="inline-flex items-center gap-1 rounded-full border border-dashed border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                {/* アイコンは視覚的なスキャン補助。項目名は sr-only で支援技術に渡す。 */}
                                <span className="sr-only">稼働可能時期：</span>
                                <Clock aria-hidden="true" className="h-3 w-3 text-muted-foreground" />
                                {engineer.available_from ? (
                                    engineer.available_label
                                ) : (
                                    <EmptyValue field="availableFrom" withFieldName />
                                )}
                            </span>
                            {/* 担当／サブはイニシャルアバターに圧縮する（幅が固定されカード間で比較しやすい）。
                                氏名はホバーのツールチップ、支援技術には sr-only の「担当：氏名」を渡す。 */}
                            <span className="flex items-center gap-1">
                                {/* アバターが置き換えたのは「氏名」であって項目名ではない。
                                    型3（同型の人名が2つ並ぶ）は項目名が要るため、ラベルは残す。 */}
                                <span className="text-[11px] text-muted-foreground">担当</span>
                                <UserAvatar role="担当" name={engineer.users.main.name} />
                                <UserAvatar role="サブ" name={engineer.users.sub?.name ?? null} />
                            </span>
                        </div>
                    </div>

                    <div className="my-2 h-px bg-border/60" />

                    {/* スキル */}
                    <FieldRow label="スキル">
                        {engineer.skills.length > 0 ? (
                            <CollapsibleTagRow>
                                {engineer.skills.map((s, i) => (
                                    <SkillTag key={`${s.label ?? ''}-${i}`} label={s.label ?? ''} />
                                ))}
                            </CollapsibleTagRow>
                        ) : (
                            <EmptyValue field="skills" className="text-[11px]" />
                        )}
                    </FieldRow>

                    {/* 工程経験。フルサイズ（16px）ではカードのタイポに対して大きいため、縮小ラッパーで
                        やや小さめ（15px / ラベル12px）に調整する。マッチングカード（14px）より一段大きい。
                        一覧では本カードが人材ごとに複数枚描画されるため、id はカードごとに一意化（idPrefix）して
                        重複を防ぐ（マッチング結果カードと同じ扱い）。 */}
                    <FieldRow label="工程経験">
                        <div className="[&_button]:h-[15px] [&_button]:w-[15px] [&_label]:text-xs [&_svg]:h-3 [&_svg]:w-3">
                            <ProcessCheckboxGroup
                                phases={phaseList}
                                values={phaseValues}
                                readOnly
                                idPrefix={`engineer-${engineer.id}-`}
                            />
                        </div>
                    </FieldRow>

                    {/* 勤務形態 */}
                    <FieldRow label="勤務形態">
                        {engineer.work_styles.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {engineer.work_styles.map((w) => (
                                    <span
                                        key={w.key}
                                        className="rounded border border-dashed border-border px-2 py-0.5 text-[11px]"
                                    >
                                        {w.name}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <EmptyValue field="workStyle" className="text-[11px]" />
                        )}
                    </FieldRow>
                </div>

                {/* 右側アクション */}
                <div className="flex w-[150px] shrink-0 flex-col items-center justify-center gap-2 border-l border-border bg-muted/30 p-3">
                    <Button
                        className="w-full"
                        variant="outline"
                        onClick={() => router.get(`/engineers/${engineer.id}`)}
                    >
                        詳細を見る
                    </Button>
                    <Button
                        className="w-full"
                        variant="outline"
                        size="sm"
                        onClick={onMatch}
                        // 提案不可の人材はマッチング対象外（サーバー側 MatchingController でも弾く）。
                        disabled={engineer.status === 'not_proposable'}
                        title={
                            engineer.status === 'not_proposable'
                                ? '提案不可の人材はマッチングを実行できません'
                                : undefined
                        }
                    >
                        <ArrowLeftRight className="mr-1 h-3.5 w-3.5" />
                        マッチング
                    </Button>
                    <span className="text-[10px] text-muted-foreground">更新：{updatedAt}</span>
                </div>
            </div>
        </div>
    );
}

