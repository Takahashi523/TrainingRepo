import { Button } from "@/Components/ui/button";
import SkillTagDetail from "@/Components/Common/SkillTagDetail";
import StatusBadge from "@/Components/Common/StatusBadge";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import {
    COMMERCIAL_FLOW_LABELS,
    PROJECT_STATUS_LABELS,
    ProjectShowPageProps,
    WORK_STYLE_LABELS,
} from "@/types/project";
import { PageProps } from "@/types";
import { Head, router, usePage } from "@inertiajs/react";
import { Check, Clock, Pencil, Trash2, Users } from "lucide-react";
import { useState } from "react";

type Props = PageProps<ProjectShowPageProps>;

function SectionCard({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="mb-4 overflow-visible rounded-md border border-border">
            <div className="rounded-t-md border-b border-border bg-muted/50 px-4 py-2.5 text-xs font-bold text-foreground">
                {title}
            </div>
            {children}
        </div>
    );
}

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start border-b border-border/50 px-4 py-2.5 last:border-b-0">
            <div className="w-44 shrink-0 pr-4 pt-0.5 text-xs font-semibold text-muted-foreground">
                {label}
            </div>
            <div className="min-w-0 flex-1 break-words text-sm text-foreground">
                {children}
            </div>
        </div>
    );
}

export default function Show({ project }: Props) {
    const { auth } = usePage<Props>().props;
    const isAdmin = auth.user.role === "admin";

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        router.delete(`/projects/${project.id}`, {
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
            onSuccess: () => setShowDeleteConfirm(false),
        });
    };

    const rateLabel = (() => {
        if (project.rate_min != null && project.rate_max != null) {
            return `${project.rate_min}万円　〜　${project.rate_max}万円`;
        }
        if (project.rate_note) {
            return project.rate_note;
        }
        return "—";
    })();

    const workLocationLabel =
        project.work_location_station || project.work_location_line
            ? `${project.work_location_station ?? ""}${
                  project.work_location_line
                      ? `（${project.work_location_line}）`
                      : ""
              }`
            : "—";

    return (
        <AuthenticatedLayout>
            <Head title="案件詳細" />

            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">
                        案件詳細
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        案件の登録情報を確認します
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        onClick={() =>
                            router.get(`/projects/${project.id}/edit`)
                        }
                    >
                        <Pencil className="mr-1.5 h-3.5 w-3.5" />
                        編集する
                    </Button>
                    {isAdmin && (
                        <div className="flex flex-col items-end gap-0.5">
                            <Button
                                variant="outline"
                                className="border-destructive text-destructive hover:bg-destructive/5"
                                onClick={() => setShowDeleteConfirm(true)}
                            >
                                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                                削除
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            <div className="max-w-3xl">
                {/* 案件サマリー */}
                <div className="mb-6 border-b border-border pb-6">
                    <p className="break-words text-2xl font-bold text-foreground">
                        {project.name}
                    </p>
                    {(project.client_name || project.commercial_flow) && (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {project.client_name &&
                                `クライアント：${project.client_name}`}
                            {project.client_name &&
                                project.commercial_flow &&
                                "　｜　"}
                            {project.commercial_flow && (
                                <>
                                    商流：
                                    {COMMERCIAL_FLOW_LABELS[
                                        project.commercial_flow
                                    ] ?? project.commercial_flow}
                                </>
                            )}
                        </p>
                    )}
                    <div className="mt-2.5 flex flex-wrap items-center gap-2">
                        <StatusBadge
                            status={project.status}
                            label={
                                PROJECT_STATUS_LABELS[project.status] ??
                                project.status
                            }
                        />
                        {project.headcount != null && (
                            <span className="rounded border border-border bg-white px-3 py-0.5 text-xs">
                                <Users className="mr-1 inline h-3 w-3" />
                                {project.headcount}名
                            </span>
                        )}
                        <span className="rounded-full border border-dashed border-border bg-muted/50 px-3 py-0.5 text-xs">
                            <Clock className="mr-1 inline h-3 w-3" />
                            {project.start_label}
                        </span>
                    </div>
                    <div className="mt-2.5 flex flex-wrap items-center gap-3 text-xs">
                        <span>
                            <span className="mr-1 font-semibold text-foreground/60">
                                担当
                            </span>
                            {project.users.main.name}
                            {project.users.sub && (
                                <>
                                    <span className="mx-1 text-border">／</span>
                                    <span className="mr-1 font-semibold text-foreground/60">
                                        サブ
                                    </span>
                                    {project.users.sub.name}
                                </>
                            )}
                        </span>
                    </div>
                </div>

                {/* 基本情報 */}
                <SectionCard title="基本情報">
                    <DetailRow label="案件名">{project.name}</DetailRow>
                    <DetailRow label="顧客名">
                        {project.client_name || "—"}
                    </DetailRow>
                    <DetailRow label="募集人数">
                        {project.headcount != null
                            ? `${project.headcount}名`
                            : "—"}
                    </DetailRow>
                    <DetailRow label="参画開始時期">
                        {project.start_label}
                    </DetailRow>
                </SectionCard>

                {/* 契約条件 */}
                <SectionCard title="契約条件">
                    <DetailRow label="単価（月額）">{rateLabel}</DetailRow>
                    <DetailRow label="商流">
                        {project.commercial_flow
                            ? (COMMERCIAL_FLOW_LABELS[
                                  project.commercial_flow
                              ] ?? project.commercial_flow)
                            : "—"}
                    </DetailRow>
                </SectionCard>

                {/* 勤務条件 */}
                <SectionCard title="勤務条件">
                    {/* [Issue #50 レビュー対応] 登録フォームでは「最寄駅」「路線名」の語の意味を確定させたため、
                        値を結合表示するこの行もラベルを実際の中身に合わせる（旧: 「勤務地（最寄駅）」のまま路線名も表示していた） */}
                    <DetailRow label="勤務地（最寄駅 / 路線名）">
                        {workLocationLabel}
                    </DetailRow>
                    <DetailRow label="稼働形態">
                        {project.work_style
                            ? (WORK_STYLE_LABELS[project.work_style] ??
                              project.work_style)
                            : "—"}
                    </DetailRow>
                    <DetailRow label="面談回数">
                        {project.interview_count != null
                            ? `${project.interview_count}回`
                            : "—"}
                    </DetailRow>
                </SectionCard>

                {/* 就業条件 */}
                <SectionCard title="就業条件">
                    <DetailRow label="精算幅">
                        {project.billing_range || "—"}
                    </DetailRow>
                    <DetailRow label="特記事項">
                        {project.remarks ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.remarks}
                            </p>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                </SectionCard>

                {/* スキル要件 */}
                <SectionCard title="スキル要件">
                    <DetailRow label="必須スキル">
                        {project.required_skills.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {project.required_skills.map((skill, i) => (
                                    <SkillTagDetail
                                        key={i}
                                        label={skill.label ?? ""}
                                        detail={skill.detail}
                                    />
                                ))}
                            </div>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                    <DetailRow label="尚可スキル">
                        {project.preferred_skills.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {project.preferred_skills.map((skill, i) => (
                                    <SkillTagDetail
                                        key={i}
                                        label={skill.label ?? ""}
                                        detail={skill.detail}
                                    />
                                ))}
                            </div>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                    <DetailRow label="対象工程">
                        <div className="flex flex-wrap gap-3">
                            {project.phases.map((phase) => (
                                <span
                                    key={phase.key}
                                    className="flex items-center gap-1 text-sm"
                                >
                                    <span
                                        className={`inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center border text-[9px] font-bold ${
                                            phase.is_target
                                                ? "border-primary bg-primary text-primary-foreground"
                                                : "border-border bg-muted/50 text-transparent"
                                        }`}
                                    >
                                        {phase.is_target && (
                                            <Check className="h-2.5 w-2.5" />
                                        )}
                                    </span>
                                    <span
                                        className={
                                            phase.is_target
                                                ? "text-foreground"
                                                : "text-muted-foreground"
                                        }
                                    >
                                        {phase.name}
                                    </span>
                                </span>
                            ))}
                        </div>
                    </DetailRow>
                    <DetailRow label="顧客折衝経験">
                        {project.negotiation_required === true
                            ? "要"
                            : project.negotiation_required === false
                              ? "不問"
                              : "—"}
                    </DetailRow>
                    <DetailRow label="業務内容詳細">
                        {project.description ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.description}
                            </p>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                    <DetailRow label="稼働環境">
                        {project.work_env ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.work_env}
                            </p>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                </SectionCard>

                {/* 管理情報 */}
                <SectionCard title="管理情報">
                    <DetailRow label="ステータス">
                        <StatusBadge
                            status={project.status}
                            label={
                                PROJECT_STATUS_LABELS[project.status] ??
                                project.status
                            }
                        />
                    </DetailRow>
                    <DetailRow label="担当営業">
                        <span>担当：{project.users.main.name}</span>
                        {project.users.sub && (
                            <span className="ml-3">
                                ／　サブ：{project.users.sub.name}
                            </span>
                        )}
                    </DetailRow>
                </SectionCard>
            </div>

            {/* Delete confirmation dialog */}
            {showDeleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-full max-w-sm rounded-lg border border-border bg-white p-6 shadow-xl">
                        <h2 className="mb-2 text-base font-bold text-foreground">
                            案件情報を削除しますか？
                        </h2>
                        <p className="mb-5 break-words text-sm text-muted-foreground">
                            <strong>{project.name}</strong>{" "}
                            の情報を物理削除します。この操作は取り消せません。
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => setShowDeleteConfirm(false)}
                            >
                                キャンセル
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={handleDelete}
                                disabled={isDeleting}
                            >
                                {isDeleting ? "削除中..." : "削除する"}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
