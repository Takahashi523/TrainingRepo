import ProjectForm from "@/Components/Projects/ProjectForm";
import { ProjectFormData, ProjectCreatePageProps } from "@/types/project";
import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { PageProps } from "@/types";
import { Head, router, useForm } from "@inertiajs/react";
import { Loader2 } from "lucide-react";
import { useEffect } from "react";

type Props = PageProps<ProjectCreatePageProps>;

export default function Create({
    fieldSettings,
    phases,
    work_styles,
    commercial_flows,
    statuses,
    users,
    authUserId,
}: Props) {
    const form = useForm<ProjectFormData>({
        name: "",
        client_name: "",
        headcount: "",
        start_date: "",
        rate_is_negotiable: false,
        rate_min: "",
        rate_max: "",
        rate_note: "",
        commercial_flow: "",
        work_style: "",
        interview_count: "",
        work_location_line: "",
        work_location_station: "",
        required_skills: [{ id: crypto.randomUUID(), label: "", detail: "" }],
        preferred_skills: [],
        proc_requirements: false,
        proc_basic_design: false,
        proc_detail_design: false,
        proc_development: false,
        proc_testing: false,
        proc_maintenance: false,
        negotiation_required: false,
        description: "",
        work_env: "",
        status: "open",
        main_user_id: String(authUserId),
        sub_user_id: "",
        billing_range: "",
        remarks: "",
    });

    const { processing, errors } = form;

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
            headcount: data.headcount !== "" ? Number(data.headcount) : null,
            rate_min: data.rate_min !== "" ? Number(data.rate_min) : null,
            rate_max: data.rate_max !== "" ? Number(data.rate_max) : null,
            interview_count:
                data.interview_count !== ""
                    ? Number(data.interview_count)
                    : null,

            required_skills: data.required_skills
                .filter((s) => s.label !== "" || s.detail !== "")
                .map(({ label, detail }) => ({ label, detail })),
            preferred_skills: data.preferred_skills
                .filter((s) => s.label !== "" || s.detail !== "")
                .map(({ label, detail }) => ({ label, detail })),
        }));

        form.post(route("projects.store"));
    };

    return (
        <AuthenticatedLayout>
            <Head title="案件登録" />

            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">
                        案件登録
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        新規案件情報を登録します
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get("/projects")}
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
