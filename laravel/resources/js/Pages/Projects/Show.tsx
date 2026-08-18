import { Button } from "@/Components/ui/button";
import ProcessCheckboxGroup, {
    buildProcessPhaseProps,
} from "@/Components/Common/ProcessCheckboxGroup";
import EmptyValue from "@/Components/Common/EmptyValue";
import FieldRow from "@/Components/Common/FieldRow";
import MetaRow, { MetaItem } from "@/Components/Common/MetaRow";
import Rate from "@/Components/Common/Rate";
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
import { Clock, Pencil, Trash2, Users } from "lucide-react";
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


export default function Show({ project }: Props) {
    const { auth } = usePage<Props>().props;
    const isAdmin = auth.user.role === "admin";

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    // 対象工程は共通 ProcessCheckboxGroup で表示する（案件のフラグ名は is_target）。
    const { phaseList, phaseValues } = buildProcessPhaseProps(
        project.phases,
        "is_target",
    );

    const handleDelete = () => {
        router.delete(`/projects/${project.id}`, {
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
            onSuccess: () => setShowDeleteConfirm(false),
        });
    };

    // 欠損は EmptyValue（淡色）で描くため、ここでは値がある場合のみ文字列を組み立てる。
    const workLocationLabel =
        project.work_location_station || project.work_location_line
            ? `${project.work_location_station ?? ""}${
                  project.work_location_line
                      ? `（${project.work_location_line}）`
                      : ""
              }`
            : null;

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
                    {/* 見出し直下サマリー（表示規約の型2）：ラベル語は出さず、項目名は sr-only で渡す。
                        一覧カード・マッチング結果と同じ表現。ここは下部の項目表に全項目が必ず出るため、
                        値がある項目だけを出してよい（規約 §2 の例外）。 */}
                    {(project.client_name || project.commercial_flow) && (
                        <MetaRow className="mt-0.5 text-xs">
                            {project.client_name && (
                                <MetaItem field="clientName">
                                    {project.client_name}
                                </MetaItem>
                            )}
                            {project.commercial_flow && (
                                <MetaItem field="commercialFlow">
                                    {COMMERCIAL_FLOW_LABELS[
                                        project.commercial_flow
                                    ] ?? project.commercial_flow}
                                </MetaItem>
                            )}
                        </MetaRow>
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
                            <span className="rounded-full border border-border bg-muted/50 px-3 py-0.5 text-xs">
                                {/* アイコンは装飾。項目名は sr-only で支援技術に渡す。 */}
                                <span className="sr-only">募集人数：</span>
                                <Users aria-hidden="true" className="mr-1 inline h-3 w-3 text-muted-foreground" />
                                {project.headcount}名
                            </span>
                        )}
                        {/* サマリーは「値がある項目だけを出す」（下部の項目表に全項目が必ず出るため）。
                            募集人数バッジ・クライアント／商流と同じ扱いにそろえ、未定のときはピルごと出さない。
                            アイコンは視覚的なスキャン補助で、項目名は sr-only で支援技術に渡す。 */}
                        {project.start_date && (
                            <span className="rounded-full border border-dashed border-border bg-muted/50 px-3 py-0.5 text-xs">
                                <span className="sr-only">参画開始時期：</span>
                                <Clock aria-hidden="true" className="mr-1 inline h-3 w-3 text-muted-foreground" />
                                {project.start_label}
                            </span>
                        )}
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
                    <FieldRow density="detail" label="案件名">{project.name}</FieldRow>
                    <FieldRow density="detail" label="顧客名">
                        {project.client_name || <EmptyValue field="clientName" />}
                    </FieldRow>
                    <FieldRow density="detail" label="募集人数">
                        {project.headcount != null ? (
                            `${project.headcount}名`
                        ) : (
                            <EmptyValue field="headcount" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="参画開始時期">
                        {/* サーバの start_label は null のとき「未定」を返すが、欠損は控えめな色で
                            見せるため値の有無で描き分ける（色はトークン側が持つ）。 */}
                        {project.start_date ? (
                            project.start_label
                        ) : (
                            <EmptyValue field="startDate" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* 契約条件 */}
                <SectionCard title="契約条件">
                    <FieldRow density="detail" label="単価（月額）">
                        <Rate
                            min={project.rate_min}
                            max={project.rate_max}
                            note={project.rate_note}
                        />
                    </FieldRow>
                    <FieldRow density="detail" label="商流">
                        {project.commercial_flow
                            ? (COMMERCIAL_FLOW_LABELS[
                                  project.commercial_flow
                              ] ?? project.commercial_flow)
                            : <EmptyValue field="commercialFlow" />}
                    </FieldRow>
                </SectionCard>

                {/* 勤務条件 */}
                <SectionCard title="勤務条件">
                    <FieldRow density="detail" label="勤務地（最寄駅）">
                        {workLocationLabel ?? <EmptyValue field="workLocation" />}
                    </FieldRow>
                    <FieldRow density="detail" label="稼働形態">
                        {project.work_style
                            ? (WORK_STYLE_LABELS[project.work_style] ??
                              project.work_style)
                            : <EmptyValue field="workStyle" />}
                    </FieldRow>
                    <FieldRow density="detail" label="面談回数">
                        {project.interview_count != null ? (
                            `${project.interview_count}回`
                        ) : (
                            <EmptyValue field="interviewCount" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* 就業条件 */}
                <SectionCard title="就業条件">
                    <FieldRow density="detail" label="精算幅">
                        {project.billing_range || <EmptyValue field="billingRange" />}
                    </FieldRow>
                    <FieldRow density="detail" label="特記事項">
                        {project.remarks ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.remarks}
                            </p>
                        ) : (
                            <EmptyValue field="remarks" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* スキル要件 */}
                <SectionCard title="スキル要件">
                    <FieldRow density="detail" label="必須スキル">
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
                            <EmptyValue field="skills" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="尚可スキル">
                        {project.preferred_skills.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {project.preferred_skills.map((skill, i) => (
                                    <SkillTagDetail
                                        key={i}
                                        label={skill.label ?? ""}
                                        detail={skill.detail}
                                        skillType="preferred"
                                    />
                                ))}
                            </div>
                        ) : (
                            <EmptyValue field="skills" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="対象工程">
                        {/* 人材詳細（Pages/Engineers/Show.tsx）と同一の指定にそろえ、
                            同じ「工程」が画面ごとに違う見え方になるのを防ぐ。 */}
                        <ProcessCheckboxGroup
                            phases={phaseList}
                            values={phaseValues}
                            readOnly
                            className="flex-nowrap gap-x-4"
                        />
                    </FieldRow>
                    <FieldRow density="detail" label="顧客折衝経験">
                        {project.negotiation_required === true
                            ? "要"
                            : project.negotiation_required === false
                              ? "不問"
                              : <EmptyValue field="negotiationExp" />}
                    </FieldRow>
                    <FieldRow density="detail" label="業務内容詳細">
                        {project.description ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.description}
                            </p>
                        ) : (
                            <EmptyValue field="description" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="稼働環境">
                        {project.work_env ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {project.work_env}
                            </p>
                        ) : (
                            <EmptyValue field="workEnv" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* 管理情報 */}
                <SectionCard title="管理情報">
                    <FieldRow density="detail" label="ステータス">
                        <StatusBadge
                            status={project.status}
                            label={
                                PROJECT_STATUS_LABELS[project.status] ??
                                project.status
                            }
                        />
                    </FieldRow>
                    <FieldRow density="detail" label="担当営業">
                        <span>担当：{project.users.main.name}</span>
                        {project.users.sub && (
                            <span className="ml-3">
                                ／　サブ：{project.users.sub.name}
                            </span>
                        )}
                    </FieldRow>
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
