import ProjectForm from "@/Components/Projects/ProjectForm";
import {
    Project,
    ProjectEditPageProps,
    ProjectFormData,
} from "@/types/project";
import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { PageProps } from "@/types";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { Loader2 } from "lucide-react";
import { useEffect } from "react";

type Props = PageProps<ProjectEditPageProps>;

function toFormData(project: Project): ProjectFormData {
    // phases は [{key, name, is_target}] で来るので proc_* の真偽値に変換する
    const procValuesByKey = Object.fromEntries(
        project.phases.map((phase) => [phase.key, phase.is_target]),
    ) as Record<string, boolean>;

    return {
        name: project.name,
        client_name: project.client_name ?? "",
        headcount: project.headcount != null ? String(project.headcount) : "",
        start_date: project.start_date ?? "",
        // rate_min/rate_max が両方nullかつrate_noteが入っている場合のみスキル見合いとみなす
        // （rate_noteはProjectService側でrate_is_negotiable=falseの場合は必ずnullになる保証があるため、
        //  「単価未入力（非スキル見合い）」と「スキル見合い」を判別できる）
        rate_is_negotiable:
            project.rate_min === null &&
            project.rate_max === null &&
            project.rate_note !== null,
        rate_min: project.rate_min != null ? String(project.rate_min) : "",
        rate_max: project.rate_max != null ? String(project.rate_max) : "",
        rate_note: project.rate_note ?? "",
        commercial_flow: project.commercial_flow ?? "",
        work_style: project.work_style ?? "",
        interview_count:
            project.interview_count != null
                ? String(project.interview_count)
                : "",
        work_location_line: project.work_location_line ?? "",
        work_location_station: project.work_location_station ?? "",
        required_skills:
            project.required_skills.length > 0
                ? project.required_skills.map((s) => ({
                      id: crypto.randomUUID(),
                      label: s.label ?? "",
                      detail: s.detail,
                  }))
                : [{ id: crypto.randomUUID(), label: "", detail: "" }],
        preferred_skills: project.preferred_skills.map((s) => ({
            id: crypto.randomUUID(),
            label: s.label ?? "",
            detail: s.detail,
        })),
        proc_requirements: procValuesByKey.proc_requirements ?? false,
        proc_basic_design: procValuesByKey.proc_basic_design ?? false,
        proc_detail_design: procValuesByKey.proc_detail_design ?? false,
        proc_development: procValuesByKey.proc_development ?? false,
        proc_testing: procValuesByKey.proc_testing ?? false,
        proc_maintenance: procValuesByKey.proc_maintenance ?? false,
        negotiation_required: project.negotiation_required ?? false,
        description: project.description ?? "",
        work_env: project.work_env ?? "",
        status: project.status,
        main_user_id: String(project.users.main.id),
        sub_user_id: project.users.sub ? String(project.users.sub.id) : "",
        billing_range: project.billing_range ?? "",
        remarks: project.remarks ?? "",
    };
}

export default function Edit({
    project,
    fieldSettings,
    phases,
    work_styles,
    commercial_flows,
    statuses,
    users,
}: Props) {
    const form = useForm<ProjectFormData>(toFormData(project));

    const { processing, errors } = form;

    // 楽観ロック（version）の競合で保存が拒否され、同じ編集画面へ差し戻された場合の対応（issue #45）。
    // このページは「この project を編集する」単一目的の画面のため、project Props が変わるのは
    // 基本的にこの「競合後の再取得」のケースのみ。useForm の初期値はマウント時の1回しか使われず、
    // Props 更新だけでは同一コンポーネントの再マウントが起きないため、明示的にフォームを作り直す。
    //
    // 通常のバリデーションエラー（422）で back() された場合も project Props は（保存に失敗して
    // DBが変わっていないとはいえ）新しいオブジェクトとして再取得されるため、ここで無条件に
    // setData すると入力中の内容がバリデーションエラーのたびに消えてしまう不具合があった。
    // バリデーションエラー時は errors が入る一方、バージョン競合時は errors を伴わないため、
    // errors が無いときだけ再同期することで両者を区別する……はずだったが、判定に使う errors を
    // useForm の内部 state（form.errors、下の scroll 用 effect が使っているもの）から取ると、
    // page props（project 含む）の更新と useForm 内部の setErrors（visit の onError コールバックで
    // 発火）が別タイミングのレンダーになることがあり、1回目の保存失敗時だけ「新しい project で
    // 再レンダーされた時点ではまだ form.errors が空のまま」というレースが起きて入力が消えてしまう
    // 不具合があった（2回目以降の失敗では前回の form.errors が残っているため偶然ガードが効いていた）。
    // project と同じ page.props の中身は usePage() から取れば同一のレンダーで確実に同期するため、
    // このガードだけは form.errors ではなく usePage().props.errors を判定に使う
    // （下のスクロール effect は DOM 表示と連動させたいので、引き続き form.errors のままでよい）。
    const { errors: pageErrors } = usePage().props;

    useEffect(() => {
        if (Object.keys(pageErrors).length > 0) return;
        form.setData(toFormData(project));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project]);

    // form.errorsが更新され、DOMに反映された後に実行されることを保証するためuseEffectを使う
    // （onError内でrequestAnimationFrameを使う方式だと、Reactのコミット前にクエリが走ることがあり、
    // 初回のエラー表示時だけスクロールされないことがあった）
    useEffect(() => {
        if (Object.keys(errors).length === 0) return;

        document.querySelector(".text-destructive")?.scrollIntoView({
            behavior: "smooth",
            block: "center",
        });
    }, [errors]);

    const handleSubmit = () => {
        form.transform((data) => ({
            ...data,
            client_name: data.client_name || null,
            start_date: data.start_date || null,
            commercial_flow: data.commercial_flow || null,
            work_style: data.work_style || null,
            // フルリモートの場合は勤務地を保存しない（フォーム上は切替時に値を保持し、
            // 送信時にのみnull化することでonsite/hybridへ戻したときに再入力の手間をなくす）
            work_location_line:
                data.work_style === "remote"
                    ? null
                    : data.work_location_line || null,
            work_location_station:
                data.work_style === "remote"
                    ? null
                    : data.work_location_station || null,
            rate_note: data.rate_note || null,
            description: data.description || null,
            work_env: data.work_env || null,
            billing_range: data.billing_range || null,
            remarks: data.remarks || null,
            sub_user_id: data.sub_user_id || null,
            // 数値欄は Number() で変換しない。Number("あ")=NaN が JSON 化で null になり、
            // nullable 項目をサイレントに NULL 保存してしまう（silent rejection の再発）。
            // 生文字列のまま送り、サーバの integer ルールで "あ"/"1.5" を 422 として弾く（#67）。
            headcount: data.headcount !== "" ? data.headcount : null,
            rate_min: data.rate_min !== "" ? data.rate_min : null,
            rate_max: data.rate_max !== "" ? data.rate_max : null,
            interview_count:
                data.interview_count !== "" ? data.interview_count : null,

            required_skills: data.required_skills
                .filter((s) => s.label !== "" || s.detail !== "")
                .map(({ label, detail }) => ({ label, detail })),
            preferred_skills: data.preferred_skills
                .filter((s) => s.label !== "" || s.detail !== "")
                .map(({ label, detail }) => ({ label, detail })),
            version: project.version,
        }));

        form.put(route("projects.update", project.id));
    };

    return (
        <AuthenticatedLayout>
            <Head title="案件編集" />

            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">
                        案件編集
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        登録済みの案件情報を編集します
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get(`/projects/${project.id}`)}
                    >
                        キャンセル
                    </Button>
                    <Button
                        type="button"
                        onClick={handleSubmit}
                        disabled={processing}
                    >
                        {processing && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        {processing ? "保存中..." : "保存する"}
                    </Button>
                </div>
            </div>

            <ProjectForm
                form={form}
                fieldSettings={fieldSettings}
                phases={phases}
                work_styles={work_styles}
                commercial_flows={commercial_flows}
                statuses={statuses}
                users={users}
            />
        </AuthenticatedLayout>
    );
}
