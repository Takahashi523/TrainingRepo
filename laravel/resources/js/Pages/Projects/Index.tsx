import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { ProjectIndexPageProps } from "@/types/project";
import { PageProps } from "@/types";
import { Head, router } from "@inertiajs/react";
import { Plus } from "lucide-react";

type Props = PageProps<ProjectIndexPageProps>;

export default function Index({ projects }: Props) {
    return (
        <AuthenticatedLayout>
            <Head title="案件一覧" />

            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">
                        案件一覧
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        登録案件の検索・絞り込みと詳細確認
                    </p>
                </div>
                <Button onClick={() => router.get("/projects/create")}>
                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                    新規案件登録
                </Button>
            </div>

            <div className="max-w-3xl">
                <div className="overflow-visible rounded-md border border-border">
                    {projects.length > 0 ? (
                        projects.map((project) => (
                            <button
                                key={project.id}
                                type="button"
                                onClick={() =>
                                    router.get(`/projects/${project.id}`)
                                }
                                className="flex w-full items-center border-b border-border/50 px-4 py-3 text-left text-sm text-foreground last:border-b-0 hover:bg-muted/50"
                            >
                                {project.name}
                            </button>
                        ))
                    ) : (
                        <p className="px-4 py-6 text-sm text-muted-foreground">
                            案件が登録されていません。
                        </p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
