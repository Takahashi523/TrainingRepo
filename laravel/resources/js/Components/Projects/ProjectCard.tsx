import StatusBadge, { STATUS_STYLES } from "@/Components/Common/StatusBadge";
import SkillTag from "@/Components/Common/SkillTag";
import { Button } from "@/Components/ui/button";
import { cn } from "@/lib/utils";
import {
    COMMERCIAL_FLOW_LABELS,
    PROJECT_STATUS_LABELS,
    ProjectListItem,
    WORK_STYLE_LABELS,
} from "@/types/project";
import { router } from "@inertiajs/react";
import { Clock, Users } from "lucide-react";

const MAX_SKILLS_VISIBLE = 5;

interface Props {
    project: ProjectListItem;
}

// 単価表示ロジック（API doc 04 参照）：
// ① rate_min/rate_max があれば範囲表示 ② 無くても rate_note があればそれを表示 ③ どちらも無ければ「—」
// 数値のときは金額（濃色・太字）と単位「万円」（淡色・通常ウェイト）を分けて描画する
// （WF_06 .rate-display / .rate-unit 準拠。PRレビュー #53 指摘：単価のみ一律太字だと見出しと焦点が競合するため）。
// ※単価描画の共通部品化（MatchCard等との統合）は別issueで扱う想定で、ここではProjectCard内の見た目改善に留める。
function RateValue({
    rateMin,
    rateMax,
    rateNote,
}: {
    rateMin: number | null;
    rateMax: number | null;
    rateNote: string | null;
}) {
    if (rateMin != null && rateMax != null) {
        return (
            <span className="break-words text-[13px] font-semibold text-foreground">
                {rateMin}
                <span className="text-[11px] font-normal text-muted-foreground">
                    万円
                </span>
                〜{rateMax}
                <span className="text-[11px] font-normal text-muted-foreground">
                    万円
                </span>
            </span>
        );
    }
    return (
        <span className="break-words text-[13px] font-semibold text-foreground">
            {rateNote ?? "—"}
        </span>
    );
}

export default function ProjectCard({ project }: Props) {
    const accentClass =
        STATUS_STYLES[project.status]?.accentClass ?? "bg-gray-400";
    const isClosed = project.status === "closed";

    const visibleRequired = project.required_skills.slice(
        0,
        MAX_SKILLS_VISIBLE,
    );
    const hiddenRequiredCount = Math.max(
        0,
        project.required_skills.length - MAX_SKILLS_VISIBLE,
    );
    const visiblePreferred = project.preferred_skills.slice(
        0,
        MAX_SKILLS_VISIBLE,
    );
    const hiddenPreferredCount = Math.max(
        0,
        project.preferred_skills.length - MAX_SKILLS_VISIBLE,
    );

    const updatedAt = new Date(project.updated_at).toLocaleDateString("ja-JP", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });

    return (
        <div
            className={cn(
                "mb-4 overflow-hidden rounded-md border border-border bg-white",
                // 終了案件はopacity低めで表示（WF_06確定事項）
                isClosed && "opacity-[0.65]",
            )}
        >
            <div className="flex items-stretch">
                <div className={cn("w-1.5 shrink-0", accentClass)} />

                <div className="min-w-0 flex-1 p-4">
                    {/* 上段：案件名 + バッジ群 */}
                    <div className="flex flex-wrap items-start gap-3">
                        <div className="min-w-0">
                            <p className="break-words text-base font-bold text-foreground">
                                {project.name}
                            </p>
                            <p className="mt-0.5 break-words text-[11px] text-muted-foreground">
                                クライアント：{project.client_name ?? "—"}
                                　｜　商流：
                                {project.commercial_flow
                                    ? (COMMERCIAL_FLOW_LABELS[
                                          project.commercial_flow
                                      ] ?? project.commercial_flow)
                                    : "—"}
                            </p>
                        </div>
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <StatusBadge
                                status={project.status}
                                label={
                                    PROJECT_STATUS_LABELS[project.status] ??
                                    project.status
                                }
                            />
                            {project.interview_count != null && (
                                <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                    面談 {project.interview_count}回
                                </span>
                            )}
                            <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                <Users className="h-3 w-3" />
                                {project.headcount != null
                                    ? `${project.headcount}名`
                                    : "—"}
                            </span>
                            <span className="text-[10px] text-muted-foreground">
                                担当：{project.users.main.name}
                                <span className="mx-1">/</span>
                                サブ：
                                {project.users.sub
                                    ? project.users.sub.name
                                    : "未割当"}
                            </span>
                        </div>
                    </div>

                    <div className="my-2 h-px bg-border/60" />

                    {/* 必須スキル／尚可スキル */}
                    <Section label="必須スキル">
                        <div className="flex flex-wrap items-center gap-1.5">
                            {visibleRequired.length > 0 ? (
                                <>
                                    {visibleRequired.map((s, i) => (
                                        <SkillTag
                                            key={`r-${i}`}
                                            label={s.label ?? ""}
                                        />
                                    ))}
                                    {hiddenRequiredCount > 0 && (
                                        <span className="rounded border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground">
                                            +{hiddenRequiredCount}
                                        </span>
                                    )}
                                </>
                            ) : (
                                <span className="text-[11px] text-muted-foreground">
                                    —
                                </span>
                            )}
                            {visiblePreferred.length > 0 && (
                                <>
                                    <span className="ml-1 text-[10px] text-muted-foreground">
                                        尚可→
                                    </span>
                                    {visiblePreferred.map((s, i) => (
                                        <SkillTag
                                            key={`p-${i}`}
                                            label={s.label ?? ""}
                                            skillType="preferred"
                                        />
                                    ))}
                                    {hiddenPreferredCount > 0 && (
                                        <span className="rounded border border-dashed border-border px-2 py-0.5 text-[11px] text-muted-foreground">
                                            +{hiddenPreferredCount}
                                        </span>
                                    )}
                                </>
                            )}
                        </div>
                    </Section>

                    {/* 単価 + 稼働開始 + 勤務形態 */}
                    <div className="flex flex-wrap items-start gap-x-4 gap-y-1.5">
                        <Section label="単価">
                            <RateValue
                                rateMin={project.rate_min}
                                rateMax={project.rate_max}
                                rateNote={project.rate_note}
                            />
                        </Section>
                        <Section label="稼働開始">
                            {/* ラベル「稼働開始」と重複しないよう、装飾ピルは外して単価行と同型の
                                「ラベル＋値テキスト」にする（PRレビュー #53 指摘）。スキャン用の
                                小さなClockアイコンはインラインで残す。 */}
                            <span className="inline-flex items-center gap-1 break-words text-[13px] font-semibold text-foreground">
                                <Clock className="h-3 w-3 shrink-0 text-muted-foreground" />
                                {project.start_label}
                            </span>
                        </Section>
                        <Section label="勤務形態">
                            {project.work_style ? (
                                <span className="rounded border border-dashed border-border px-2 py-0.5 text-[11px]">
                                    {WORK_STYLE_LABELS[project.work_style] ??
                                        project.work_style}
                                </span>
                            ) : (
                                <span className="text-[11px] text-muted-foreground">
                                    —
                                </span>
                            )}
                        </Section>
                    </div>
                </div>

                {/* 右側アクション */}
                <div className="flex w-[150px] shrink-0 flex-col items-center justify-center gap-2 border-l border-border bg-muted/30 p-3">
                    <Button
                        className="w-full"
                        variant="outline"
                        onClick={() => router.get(`/projects/${project.id}`)}
                    >
                        詳細を見る
                    </Button>
                    <span className="text-[10px] text-muted-foreground">
                        更新：{updatedAt}
                    </span>
                </div>
            </div>
        </div>
    );
}

function Section({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="mb-1.5 flex min-w-0 items-start gap-2">
            <span className="w-14 shrink-0 pt-0.5 text-[10px] font-bold text-muted-foreground">
                {label}
            </span>
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}
