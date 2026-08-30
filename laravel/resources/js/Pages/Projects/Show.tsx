import { Button } from "@/Components/ui/button";
import ConfirmDialog from "@/Components/Common/ConfirmDialog";
import ProcessCheckboxGroup, {
    buildProcessPhaseProps,
} from "@/Components/Common/ProcessCheckboxGroup";
import EmptyValue from "@/Components/Common/EmptyValue";
import FieldRow from "@/Components/Common/FieldRow";
import MetaRow, { MetaItem } from "@/Components/Common/MetaRow";
import Rate from "@/Components/Common/Rate";
import SkillTagDetail from "@/Components/Common/SkillTagDetail";
import StatusBadge from "@/Components/Common/StatusBadge";
import TruncatedText from "@/Components/Common/TruncatedText";
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
        <div className="mb-4 overflow-visible rounded-md border border-border bg-white">
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

    return (
        <AuthenticatedLayout mainClassName="bg-muted/30">
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
                    {/* ステータスは案件名の右に置く（マッチングサマリー・人材詳細と同じ構成）。 */}
                    <div className="flex flex-wrap items-center gap-2">
                        {/* 案件名（最大255文字）は1行省略し、省略時のみホバーで全文を出す。
                            flex-1（基準サイズ0）が必須：min-w-0 だけでは「折り返すか」の判定に使う
                            基準サイズが max-content（truncate により全文1行分）のままなので、
                            案件名が親幅を超えた時点で案件名が1行目を独占し、ステータスバッジが2行目へ落ちる。
                            flex は基準サイズで折り返しを先に決め、縮小はその後に行うため。
                            max-w-fit とセットで使う：flex-1 だけだと案件名が余白まで伸びて
                            ステータスバッジが右端まで離れるので、内容幅以上には伸ばさない。人材詳細と同じ組み方。 */}
                        <TruncatedText
                            as="p"
                            text={project.name}
                            className="min-w-0 max-w-fit flex-1 text-2xl font-bold text-foreground"
                        />
                        <StatusBadge
                            status={project.status}
                            label={
                                PROJECT_STATUS_LABELS[project.status] ??
                                project.status
                            }
                            className="shrink-0"
                        />
                    </div>
                    {/* 属性メタ（型2）と担当／サブ（型3）を1つの流れに並べる。
                        担当・サブは同型の人名が並ぶため型3 のラベルを維持する。
                        サマリーなので値がある項目だけを出す（全項目は下部の項目表に出る）。 */}
                    {/* サマリーのメタは1行に収める（nowrap）。はみ出し分は data-shrinkable を付けた
                        可変長項目（顧客名／担当・サブ）だけが引き受け、商流・募集人数・参画開始は縮まない。 */}
                    <MetaRow nowrap className="mt-2.5 text-xs">
                        {project.client_name && (
                            <MetaItem field="clientName" data-shrinkable>
                                {/* 顧客名（最大100文字）は1行省略＋省略時のみホバー全文。 */}
                                <TruncatedText
                                    text={project.client_name}
                                    className="min-w-0 max-w-fit flex-1"
                                />
                            </MetaItem>
                        )}
                        {project.commercial_flow && (
                            <MetaItem field="commercialFlow">
                                {COMMERCIAL_FLOW_LABELS[project.commercial_flow] ??
                                    project.commercial_flow}
                            </MetaItem>
                        )}
                        {project.headcount != null && (
                            <MetaItem field="headcount" icon={Users}>
                                {project.headcount}名
                            </MetaItem>
                        )}
                        {project.start_date && (
                            <MetaItem field="startDate" icon={Clock}>
                                {project.start_label}
                            </MetaItem>
                        )}
                        {/* 担当・サブは同型の人名が並ぶため型3 のラベルを維持する。
                            氏名（最大255文字）だけを1行省略し、ラベル語と区切り「／」は shrink-0 で常時表示する
                            （担当が長いだけで「サブ：」ごと消えるのを防ぐ）。人材詳細と同じ組み方。
                            氏名2つは flex-1 max-w-fit で「取り分は等分・使わない分は相手に返す」にする。
                            既定の縮小は基準サイズに比例するため、担当だけが長いとサブが1文字まで潰れる。 */}
                        {/* ラベル語を可視で持つ型3 の項目だが、MetaItem を通すことで
                            data-shrinkable が型で守られる（生の span に直接書くと綴り間違いが
                            無言で「縮まない項目」に化け、行あふれとしてしか現れない）。
                            sr-only は valueHasFieldName で抑止（可視の「担当：」と二重に読ませない）。
                            gap-0：ラベルと氏名の間に MetaItem 既定の隙間を入れない。 */}
                        <MetaItem
                            field="mainUser"
                            valueHasFieldName
                            data-shrinkable
                            className="gap-0"
                        >
                            <span className="shrink-0">担当：</span>
                            <TruncatedText
                                text={project.users.main.name}
                                className="min-w-0 max-w-fit flex-1"
                            />
                            {project.users.sub && (
                                <>
                                    <span className="mx-1 shrink-0 text-border">／</span>
                                    <span className="shrink-0">サブ：</span>
                                    <TruncatedText
                                        text={project.users.sub.name}
                                        className="min-w-0 max-w-fit flex-1"
                                    />
                                </>
                            )}
                        </MetaItem>
                    </MetaRow>

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
                    {/* [Issue #43] 面談回数は「何名を・何回会って選び・いつ参画するか」という
                        募集〜選考〜参画の時系列の一部なので、勤務条件ではなく基本情報に置く。 */}
                    <FieldRow density="detail" label="面談回数">
                        {project.interview_count != null ? (
                            `${project.interview_count}回`
                        ) : (
                            <EmptyValue field="interviewCount" />
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
                    {/* [Issue #43] 精算幅は「80万円 / 140〜180h」と単価とセットで意味を成す取引条件なので、
                        単価の直後に置き、性質の異なる商流を間に挟まない。 */}
                    <FieldRow density="detail" label="精算幅">
                        {project.billing_range || <EmptyValue field="billingRange" />}
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
                    {/* [Issue #43] 「どう働くか（稼働形態）」→「どこで働くか（勤務地）」の順にする。
                        勤務地の行はフルリモートで消えるため、常に出る稼働形態を先頭に置いた方が
                        行の増減でセクションの読み出し位置が動かない。登録フォームとも同順。 */}
                    <FieldRow density="detail" label="稼働形態">
                        {project.work_style
                            ? (WORK_STYLE_LABELS[project.work_style] ??
                              project.work_style)
                            : <EmptyValue field="workStyle" />}
                    </FieldRow>
                    {/* フルリモートは勤務地を持たない項目なので行ごと出さない。
                        登録フォーム（ProjectForm）も work_style === 'remote' では入力欄自体を描かず、
                        保存時に ProjectService が最寄駅・路線名を null 化する。ここで「未設定」を出すと、
                        埋めるべき項目が空いているように読めてしまう（欠損語彙の意味と食い違う）。
                        稼働形態は直下の行に出るため、行が無いこと自体は文脈から読み取れる。

                        ただし値が入っている場合は remote でも行を出す。CSV 取込は ProjectService を
                        通さず直接 upsert するため、work_style=remote かつ勤務地ありの行が保存され得る。
                        稼働形態だけで隠すと、DB にある値が画面のどこにも出ない状態になってしまう。 */}
                    {(project.work_style !== "remote" ||
                        project.work_location_station ||
                        project.work_location_line) && (
                        /* [Issue #50 レビュー対応] 登録フォームでは「最寄駅」「路線名」の語の意味を確定させたため、
                           ラベルを実際の中身に合わせる（旧: 「勤務地（最寄駅）」のまま路線名も表示していた）。
                           値は最寄駅・路線名を1本の文字列に結合せず、項目ごとに欠損トークンを描く（人材詳細と同じ原子）。
                           結合していた頃は、路線名だけが入った行が「（山手線）」と駅名の抜けた括弧になり、
                           駅だけの行では路線名が空であることが画面から読み取れなかった。 */
                        <FieldRow density="detail" label="勤務地（最寄駅 / 路線名）">
                            {project.work_location_station || (
                                <EmptyValue field="nearestStation" />
                            )}
                            {"　／　"}
                            {project.work_location_line || (
                                <EmptyValue field="nearestLine" />
                            )}
                        </FieldRow>
                    )}
                    {/* [Issue #43] 特記事項は勤務の補足を書く自由記述で、これ1項目のために
                        「就業条件」セクションを残す必然性が無いため勤務条件の末尾に置く。 */}
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

            {/* 削除確認は共通 ConfirmDialog（AlertDialog ベース）で行う。
                手組みモーダルでは得られない role="alertdialog"・フォーカストラップ・
                Esc での閉じる・フォーカス復帰・背景の不活性化を標準機能に委ねる。 */}
            <ConfirmDialog
                open={showDeleteConfirm}
                title="案件情報を削除しますか？"
                description={
                    <>
                        <strong>{project.name}</strong>{" "}
                        の情報を物理削除します。この操作は取り消せません。
                    </>
                }
                processing={isDeleting}
                processingLabel="削除中..."
                onConfirm={handleDelete}
                onCancel={() => setShowDeleteConfirm(false)}
            />
        </AuthenticatedLayout>
    );
}
