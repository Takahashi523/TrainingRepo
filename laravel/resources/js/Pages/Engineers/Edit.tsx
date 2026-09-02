import EngineerForm, {
    EngineerFormData,
} from "@/Components/Engineers/EngineerForm";
import AiLoadingOverlay from "@/Components/Common/AiLoadingOverlay";
import { Button } from "@/Components/ui/button";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Engineer, EngineerEditPageProps } from "@/types/engineer";
import { PageProps } from "@/types";
import { Head, router, useForm, usePage } from "@inertiajs/react";
import { Loader2 } from "lucide-react";
import { useEffect } from "react";

type Props = PageProps<EngineerEditPageProps>;

function toFormData(engineer: Engineer): EngineerFormData {
    const procMap = Object.fromEntries(
        engineer.phases.map((p) => [p.key, p.has_experience]),
    );

    return {
        name: engineer.name,
        name_kana: engineer.name_kana,
        birth_date: engineer.birth_date ? engineer.birth_date.slice(0, 10) : "",
        nearest_line: engineer.nearest_line ?? "",
        nearest_station: engineer.nearest_station ?? "",
        available_from: engineer.available_from
            ? engineer.available_from.slice(0, 10)
            : "",
        skills: engineer.skills.map((s) => ({
            label: s.label ?? "",
            detail: s.detail,
        })),
        proc_requirements: procMap.proc_requirements ?? false,
        proc_basic_design: procMap.proc_basic_design ?? false,
        proc_detail_design: procMap.proc_detail_design ?? false,
        proc_development: procMap.proc_development ?? false,
        proc_testing: procMap.proc_testing ?? false,
        proc_maintenance: procMap.proc_maintenance ?? false,
        has_negotiation_exp:
            engineer.has_negotiation_exp === true
                ? "1"
                : engineer.has_negotiation_exp === false
                  ? "0"
                  : "",
        appeal_note: engineer.appeal_note ?? "",
        desired_rate:
            engineer.desired_rate != null ? String(engineer.desired_rate) : "",
        work_styles: engineer.work_styles.map((w) => w.key),
        remarks: engineer.remarks ?? "",
        status: engineer.status,
        main_user_id: String(engineer.users.main.id),
        sub_user_id: engineer.users.sub ? String(engineer.users.sub.id) : "",
    };
}

export default function Edit({
    engineer,
    fieldSettings,
    phases,
    work_styles,
    statuses,
    users,
}: Props) {
    const form = useForm<EngineerFormData>(toFormData(engineer));

    const { processing } = form;

    // 楽観ロック（version）の競合で保存が拒否され、同じ編集画面へ差し戻された場合の対応（issue #45）。
    // このページは「この engineer を編集する」単一目的の画面のため、engineer Props が変わるのは
    // 基本的にこの「競合後の再取得」のケースのみ。useForm の初期値はマウント時の1回しか使われず、
    // Props 更新だけでは同一コンポーネントの再マウントが起きないため、明示的にフォームを作り直す。
    //
    // 通常のバリデーションエラー（422）で back() された場合も engineer Props は（保存に失敗して
    // DBが変わっていないとはいえ）新しいオブジェクトとして再取得されるため、ここで無条件に
    // setData すると入力中の内容がバリデーションエラーのたびに消えてしまう不具合があった。
    // バリデーションエラー時は errors が入る一方、バージョン競合時は errors を伴わないため、
    // errors が無いときだけ再同期することで両者を区別する……はずだったが、判定に使う errors を
    // useForm の内部 state（form.errors）から取ると、page props（engineer 含む）の更新と
    // useForm 内部の setErrors（visit の onError コールバックで発火）が別タイミングの
    // レンダーになることがあり、1回目の保存失敗時だけ「新しい engineer で再レンダーされた時点では
    // まだ form.errors が空のまま」というレースが起きて入力が消えてしまう不具合があった
    // （2回目以降の失敗では前回の form.errors が残っているため偶然ガードが効いていた）。
    // engineer と同じ page.props の中身は usePage() から取れば同一のレンダーで確実に同期するため、
    // ここでは form.errors ではなく usePage().props.errors を判定に使う。
    const { errors: pageErrors } = usePage().props;

    useEffect(() => {
        if (Object.keys(pageErrors).length > 0) return;
        form.setData(toFormData(engineer));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [engineer]);

    // AI 職務要約は appeal_note が変更された更新時のみ再生成される（サーバ側トリガー）。
    // ローディング表示もその条件に合わせる（更新前の値と比較）。
    const originalAppealNote = engineer.appeal_note ?? "";

    const handleSubmit = () => {
        form.transform((d) => ({
            ...d,
            has_negotiation_exp:
                d.has_negotiation_exp === "1"
                    ? true
                    : d.has_negotiation_exp === "0"
                      ? false
                      : null,
            // desired_rate は Number() で変換しない。Number("あ")=NaN が JSON 化で null になり
            // サイレント NULL 保存になるため、生文字列のまま送りサーバ integer ルールで弾く（#67）。
            desired_rate: d.desired_rate !== "" ? d.desired_rate : null,
            main_user_id: d.main_user_id !== "" ? Number(d.main_user_id) : null,
            sub_user_id: d.sub_user_id !== "" ? Number(d.sub_user_id) : null,
            birth_date: d.birth_date || null,
            available_from: d.available_from || null,
            appeal_note: d.appeal_note || null,
            remarks: d.remarks || null,
            version: engineer.version,
        }));
        form.put(`/engineers/${engineer.id}`, {
            onError: () => {
                requestAnimationFrame(() => {
                    document
                        .querySelector(".text-destructive")
                        ?.scrollIntoView({
                            behavior: "smooth",
                            block: "center",
                        });
                });
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="人材編集" />
            {/* Sticky page header */}
            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">
                        人材編集
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        登録済みの人材情報を編集します
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get(`/engineers/${engineer.id}`)}
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

            <EngineerForm
                form={form}
                fieldSettings={fieldSettings}
                phases={phases}
                work_styles={work_styles}
                statuses={statuses}
                users={users}
            />

            {/*
             * 保存リクエスト内で AI 職務要約を同期生成する（最大30秒）。appeal_note が変更された更新のみ
             * 生成が走るため、その場合だけローディングを表示する。書き込みフローのためキャンセルは付けない。
             */}
            <AiLoadingOverlay
                show={
                    processing && form.data.appeal_note !== originalAppealNote
                }
                message="AIが職務要約を生成しています…"
            />
        </AuthenticatedLayout>
    );
}
