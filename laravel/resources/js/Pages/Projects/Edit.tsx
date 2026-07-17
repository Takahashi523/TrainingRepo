import ProjectForm from "@/Components/Projects/ProjectForm";
import { Project, ProjectEditPageProps, ProjectFormData } from "@/types/project";
import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { PageProps } from "@/types";
import { Head, router, useForm } from "@inertiajs/react";
import { Loader2 } from "lucide-react";

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
        // rate_min/rate_max が両方nullならスキル見合い（rate_note使用）とみなす
        rate_is_negotiable:
            project.rate_min === null && project.rate_max === null,
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
                      label: s.label,
                      detail: s.detail,
                  }))
                : [{ id: crypto.randomUUID(), label: "", detail: "" }],
        preferred_skills: project.preferred_skills.map((s) => ({
            id: crypto.randomUUID(),
            label: s.label,
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

    const { processing } = form;

    const handleSubmit = () => {
        form.transform((data) => ({
            ...data,
            client_name: data.client_name || null,
            start_date: data.start_date || null,
            commercial_flow: data.commercial_flow || null,
            work_style: data.work_style || null,
            work_location_line: data.work_location_line || null,
            work_location_station: data.work_location_station || null,
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

        form.put(route("projects.update", project.id), {
            onError: () => {
                requestAnimationFrame(() => {
                    document.querySelector(".text-destructive")?.scrollIntoView({
                        behavior: "smooth",
                        block: "center",
                    });
                });
            },
        });
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
