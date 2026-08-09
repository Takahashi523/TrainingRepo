import ProcessCheckboxGroup from '@/Components/Engineers/ProcessCheckboxGroup';
import StatusBadge, { STATUS_STYLES } from '@/Components/Common/StatusBadge';
import SkillTag from '@/Components/Common/SkillTag';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { EngineerListItem, Phase } from '@/types/engineer';
import { router } from '@inertiajs/react';
import { ArrowLeftRight, Clock } from 'lucide-react';

const MAX_SKILLS_VISIBLE = 5;

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

    const visibleSkills = engineer.skills.slice(0, MAX_SKILLS_VISIBLE);
    const hiddenSkillsCount = Math.max(0, engineer.skills.length - MAX_SKILLS_VISIBLE);

    const phaseList: Phase[] = engineer.phases.map(({ key, name }) => ({ key, name }));
    const phaseValues = Object.fromEntries(engineer.phases.map((p) => [p.key, p.has_experience]));

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
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                {engineer.age != null && <>{engineer.age}歳</>}
                                {(engineer.nearest_station || engineer.nearest_line) && (
                                    <>
                                        　｜　最寄駅：{engineer.nearest_station ?? ''}
                                        {engineer.nearest_line && <>（{engineer.nearest_line}）</>}
                                    </>
                                )}
                            </p>
                        </div>
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <StatusBadge status={engineer.status} />
                            <span className="inline-flex items-center gap-1 rounded-full border border-dashed border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                <Clock className="h-3 w-3" />
                                {engineer.available_label}
                            </span>
                            <span className="text-[10px] text-muted-foreground">
                                担当：{engineer.users.main.name}
                                <span className="mx-1">/</span>
                                サブ：{engineer.users.sub ? engineer.users.sub.name : '未割当'}
                            </span>
                        </div>
                    </div>

                    <div className="my-2 h-px bg-border/60" />

                    {/* スキル */}
                    <Section label="スキル">
                        {visibleSkills.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-1.5">
                                {visibleSkills.map((s, i) => (
                                    <SkillTag key={i} label={s.label ?? ''} />
                                ))}
                                {hiddenSkillsCount > 0 && (
                                    <span className="rounded border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground">
                                        +{hiddenSkillsCount}
                                    </span>
                                )}
                            </div>
                        ) : (
                            <span className="text-[11px] text-muted-foreground">—</span>
                        )}
                    </Section>

                    {/* 工程経験。フルサイズ（16px）ではカードのタイポに対して大きいため、縮小ラッパーで
                        やや小さめ（15px / ラベル12px）に調整する。マッチングカード（14px）より一段大きい。 */}
                    <Section label="工程経験">
                        <div className="[&_button]:h-[15px] [&_button]:w-[15px] [&_label]:text-xs [&_svg]:h-3 [&_svg]:w-3">
                            <ProcessCheckboxGroup phases={phaseList} values={phaseValues} readOnly />
                        </div>
                    </Section>

                    {/* 勤務形態 */}
                    <Section label="勤務形態">
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
                            <span className="text-[11px] text-muted-foreground">—</span>
                        )}
                    </Section>
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

function Section({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="mb-1.5 flex items-start gap-2">
            <span className="w-14 shrink-0 pt-0.5 text-[10px] font-bold text-muted-foreground">
                {label}
            </span>
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}
