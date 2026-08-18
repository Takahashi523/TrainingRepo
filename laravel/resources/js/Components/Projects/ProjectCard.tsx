import CollapsibleTagRow from "@/Components/Common/CollapsibleTagRow";
import EmptyValue from "@/Components/Common/EmptyValue";
import FieldRow from "@/Components/Common/FieldRow";
import MetaRow, { MetaItem } from "@/Components/Common/MetaRow";
import Rate from "@/Components/Common/Rate";
import StatusBadge, { STATUS_STYLES } from "@/Components/Common/StatusBadge";
import SkillTag from "@/Components/Common/SkillTag";
import { Button } from "@/Components/ui/button";
import { emptyText } from "@/lib/emptyValue";
import { cn } from "@/lib/utils";
import {
    COMMERCIAL_FLOW_LABELS,
    PROJECT_STATUS_LABELS,
    ProjectListItem,
    WORK_STYLE_LABELS,
} from "@/types/project";
import { router } from "@inertiajs/react";
import { Clock, MessagesSquare, Users } from "lucide-react";

interface Props {
    project: ProjectListItem;
}

export default function ProjectCard({ project }: Props) {
    const accentClass =
        STATUS_STYLES[project.status]?.accentClass ?? "bg-gray-400";

    // スキルは必須→尚可の順に結合し、マッチング結果カードと同じ CollapsibleTagRow で表示する。
    // 固定件数で切ると幅の広い/狭いタグで見え方が変わるため、実幅で「1行に収まる分」を判定させる。
    const skills = [
        ...project.required_skills.map((s) => ({
            label: s.label ?? "",
            skillType: "required" as const,
        })),
        ...project.preferred_skills.map((s) => ({
            label: s.label ?? "",
            skillType: "preferred" as const,
        })),
    ];

    const updatedAt = new Date(project.updated_at).toLocaleDateString("ja-JP", {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });

    return (
        <div
            // 終了案件もカード全体は淡色化しない。状態は StatusBadge（終了）が示しており、
            // 終了案件でも詳細閲覧・編集は行えるため。カード全体の淡色化は「そのカード上の
            // アクションが実行できない」ことを示す用途に限る（例：マッチング結果カードの追加不可）。
            className="mb-4 overflow-hidden rounded-md border border-border bg-white"
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
                            {/* 見出し直下メタ（表示規約の型2）：ラベル語は出さず、項目名は sr-only で支援技術に渡す。
                                マッチング結果カード・マッチングサマリーと同じ表現に揃える。 */}
                            <MetaRow className="mt-0.5">
                                <MetaItem field="clientName">
                                    {project.client_name ??
                                        emptyText("clientName", true)}
                                </MetaItem>
                                <MetaItem field="commercialFlow">
                                    {project.commercial_flow
                                        ? (COMMERCIAL_FLOW_LABELS[
                                              project.commercial_flow
                                          ] ?? project.commercial_flow)
                                        : emptyText("commercialFlow", true)}
                                </MetaItem>
                            </MetaRow>
                        </div>
                        <div className="ml-auto flex flex-wrap items-center gap-2">
                            <StatusBadge
                                status={project.status}
                                label={
                                    PROJECT_STATUS_LABELS[project.status] ??
                                    project.status
                                }
                            />
                            {/* 面談回数・募集人数は未設定でもバッジごと消さない（消すと「値が無い」のか
                                「読み落とした」のか区別できないため）。欠損は項目名入りトークンで示す。 */}
                            <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                {/* アイコンは装飾（隣の募集人数バッジと体裁を揃えるため）。
                                    「2回」だけでは何の回数か分からないため、項目名は語「面談」が担う
                                    （型3＝値だけで自己識別できない項目にのみラベルを付ける）。 */}
                                <MessagesSquare aria-hidden="true" className="h-3 w-3 text-muted-foreground" />
                                {project.interview_count != null ? (
                                    `面談 ${project.interview_count}回`
                                ) : (
                                    <EmptyValue field="interviewCount" withFieldName />
                                )}
                            </span>
                            <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/50 px-2.5 py-0.5 text-[11px]">
                                {/* アイコンは視覚的なスキャン補助。項目名は sr-only で支援技術に渡す。 */}
                                <span className="sr-only">募集人数：</span>
                                <Users aria-hidden="true" className="h-3 w-3 text-muted-foreground" />
                                {project.headcount != null ? (
                                    `${project.headcount}名`
                                ) : (
                                    <EmptyValue field="headcount" withFieldName />
                                )}
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

                    {/* スキル（必須→尚可の順）。必須=実線 / 尚可=点線 のタグで区別できるため、
                        マッチング結果カードと同じく「尚可→」の区切り語は置かない。
                        ラベルも人材一覧カードと同じ「スキル」に揃える。 */}
                    <FieldRow label="スキル">
                        {skills.length > 0 ? (
                            <CollapsibleTagRow>
                                {skills.map((s, i) => (
                                    <SkillTag
                                        key={`${s.skillType}-${s.label}-${i}`}
                                        label={s.label}
                                        skillType={s.skillType}
                                    />
                                ))}
                            </CollapsibleTagRow>
                        ) : (
                            <EmptyValue field="skills" className="text-[11px]" />
                        )}
                    </FieldRow>

                    {/* 単価 + 参画開始 + 勤務形態。ここだけ FieldRow を横並びで使うため、
                        縦積み用の既定（上端そろえ・下マージン）を打ち消して中央そろえにする
                        （値ごとに高さが違うと横並びでは縦ズレとして見えるため）。 */}
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
                        <FieldRow label="単価" align="center">
                            <Rate
                                min={project.rate_min}
                                max={project.rate_max}
                                note={project.rate_note}
                                // カード内は周囲（メタ・タグ 11px）に合わせて 12px。詳細画面は既定の 13px。
                                className="text-xs"
                            />
                        </FieldRow>
                        <FieldRow label="参画開始" align="center">
                            {/* ラベル「参画開始」と重複しないよう、装飾ピルは外して単価行と同型の
                                「ラベル＋値テキスト」にする（PRレビュー #53 指摘）。スキャン用の
                                小さなClockアイコンはインラインで残す。 */}
                            <span className="inline-flex items-center gap-1 break-words text-xs font-semibold text-foreground">
                                <Clock aria-hidden="true" className="h-3 w-3 shrink-0 text-muted-foreground" />
                                {project.start_label}
                            </span>
                        </FieldRow>
                        <FieldRow label="勤務形態" align="center">
                            {project.work_style ? (
                                <span className="rounded border border-dashed border-border px-2 py-0.5 text-[11px]">
                                    {WORK_STYLE_LABELS[project.work_style] ??
                                        project.work_style}
                                </span>
                            ) : (
                                <EmptyValue field="workStyle" className="text-[11px]" />
                            )}
                        </FieldRow>
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

