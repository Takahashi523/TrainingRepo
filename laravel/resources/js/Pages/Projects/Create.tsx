import { useForm, Head, router } from "@inertiajs/react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Button } from "@/Components/ui/button";
// import type { User } from "@/types";
import React from "react";
import { Loader2 } from "lucide-react";
import { Input } from "@/Components/ui/input";
import { Textarea } from "@/Components/ui/textarea";
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
} from "@/Components/ui/select";
import SkillInput from "@/Components/Engineers/SkillInput";
import ProcessCheckboxGroup from "@/Components/Engineers/ProcessCheckboxGroup";

type FieldSetting = {
    is_required: boolean;
};

type FieldSettings = {
    client_name: FieldSetting;
    headcount: FieldSetting;
    start_date: FieldSetting;
    rate: FieldSetting;
    commercial_flow: FieldSetting;
    work_style: FieldSetting;
    work_location: FieldSetting;
    interview_count: FieldSetting;
    required_skills: FieldSetting;
    preferred_skills: FieldSetting;
    proc_experience: FieldSetting;
    negotiation_required: FieldSetting;
    description: FieldSetting;
    work_env: FieldSetting;
    billing_range: FieldSetting;
    remarks: FieldSetting;
};

type Props = {
    fieldSettings: FieldSettings;
    phases: { key: string; name: string }[];
    work_styles: { key: string; name: string }[];
    commercial_flows: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
    users: { id: number; name: string }[];
};

type SkillPair = {
    id?: string;
    label: string;
    detail: string;
};

const NEGOTIATION_OPTIONS = [
    { value: "false", label: "不問" },
    { value: "true", label: "要" },
] as const;

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-4 mt-9 flex items-center gap-3 [&:first-child]:mt-0">
            <span className="shrink-0 text-sm font-bold text-foreground">
                {children}
            </span>
            <div className="flex-1 border-t border-border" />
        </div>
    );
}

function FormRow({
    label,
    required = false,
    hint,
    error,
    children,
}: {
    label: string;
    required?: boolean;
    hint?: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex items-start border-b border-border/50 py-3 last:border-b-0">
            <div className="flex w-44 shrink-0 items-start gap-1.5 pr-4 pt-1.5">
                {required ? (
                    <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold leading-tight text-rose-600">
                        必須
                    </span>
                ) : (
                    <span className="rounded border border-border px-1.5 py-0.5 text-[9px] font-semibold leading-tight text-muted-foreground">
                        任意
                    </span>
                )}
                <span className="text-xs font-semibold text-foreground">
                    {label}
                </span>
            </div>
            <div className="min-w-0 flex-1 space-y-1.5">
                {children}
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        </div>
    );
}

export default function Create({
    fieldSettings,
    phases,
    work_styles,
    commercial_flows,
    statuses,
    users,
}: Props) {
    const { data, setData, post, errors, processing } = useForm({
        // 基本情報
        name: "", // 案件名
        client_name: "", // 顧客名
        headcount: "", // 募集人数
        start_date: "", // 参画開始時期

        // 契約条件
        rate_is_negotiable: false, //スキル見合いフラグ
        rate_min: "", // 単価下限
        rate_max: "", // 単価上限
        rate_note: "", // 単価備考
        commercial_flow: "", // 商流

        // 勤務条件
        work_style: "", // 稼働形態
        interview_count: "", // 面談回数
        work_location_line: "", // 路線
        work_location_station: "", // 最寄駅

        // スキル要件
        required_skills: [
            { id: crypto.randomUUID(), label: "", detail: "" },
        ] as SkillPair[], // 必須スキル

        preferred_skills: [] as SkillPair[], // 尚可スキル

        proc_requirements: false, // 対象工程 要件定義
        proc_basic_design: false, // 対象工程 基本設計
        proc_detail_design: false, // 対象工程 詳細設計
        proc_development: false, // 対象工程 開発
        proc_testing: false, // 対象工程 テスト
        proc_maintenance: false, // 対象工程 保守運用
        negotiation_required: false, // 顧客折衝経験
        description: "", // 業務内容詳細
        work_env: "", // 稼働環境

        // 管理情報
        status: "open", // ステータス
        main_user_id: "", // メイン担当営業
        sub_user_id: "", // サブ担当営業

        // 就業条件
        billing_range: "", // 清算幅
        remarks: "", // 特記事項
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route("projects.store"));
    };

    const handleRateNegotiableChange = (checked: boolean) => {
        setData((prev) => ({
            ...prev,
            rate_is_negotiable: checked,
            // 切り替えと同時に反対側の値をクリア
            ...(checked
                ? { rate_min: "", rate_max: "" } // ON → 数値欄をクリア
                : { rate_note: "" }), // OFF → 備考欄をクリア
        }));
    };

    type SkillField = "required_skills" | "preferred_skills";

    // 追加
    const addSkill = (field: SkillField) => {
        setData(field, [
            ...data[field],
            { id: crypto.randomUUID(), label: "", detail: "" },
        ]);
    };

    // 削除
    const removeSkill = (field: SkillField, id: string) => {
        setData(
            field,
            data[field].filter((s) => s.id !== id),
        );
    };

    // 更新
    const updateSkill = (
        field: SkillField,
        id: string,
        key: "label" | "detail",
        value: string,
    ) => {
        setData(
            field,
            data[field].map((s) => (s.id === id ? { ...s, [key]: value } : s)),
        );
    };

    const procValues: Record<string, boolean> = {
        proc_requirements: data.proc_requirements ?? false,
        proc_basic_design: data.proc_basic_design ?? false,
        proc_detail_design: data.proc_detail_design ?? false,
        proc_development: data.proc_development ?? false,
        proc_testing: data.proc_testing ?? false,
        proc_maintenance: data.proc_maintenance ?? false,
    };

    return (
        <AuthenticatedLayout>
            <Head title="案件登録" />

            {/* sticky ヘッダー */}
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
                        onClick={() => router.get("/engineers")}
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

            {/* Form */}
            <div className="max-w-3xl">
                <SectionHeading>基本情報</SectionHeading>

                <FormRow label="案件名" required error={errors.name}>
                    <Input
                        type="text"
                        value={data.name}
                        onChange={(e) => setData("name", e.target.value)}
                        placeholder="例：大手金融系 勘定系システム リプレース開発"
                        className={`w-full ${errors.name ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <FormRow
                    label="顧客名"
                    required={fieldSettings.client_name.is_required}
                    error={errors.client_name}
                    hint="顧客ごとの傾向分析が必要な場合に活用できます"
                >
                    <Input
                        type="text"
                        value={data.client_name}
                        onChange={(e) => setData("client_name", e.target.value)}
                        placeholder="例：○○銀行"
                        className={`w-48 ${errors.client_name ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <FormRow
                    label="募集人数"
                    required={fieldSettings.headcount.is_required}
                    error={errors.headcount}
                >
                    <div className="flex items-center gap-2">
                        <Input
                            type="number"
                            value={data.headcount}
                            onChange={(e) =>
                                setData("headcount", e.target.value)
                            }
                            placeholder="2"
                            className={`w-20 ${errors.headcount ? "border-destructive" : ""}`}
                        />
                        <span className="text-sm text-muted-foreground">
                            名
                        </span>
                    </div>
                </FormRow>

                <FormRow
                    label="参画開始時期"
                    required={fieldSettings.start_date.is_required}
                    error={errors.start_date}
                    hint="稼働開始時期のマッチングスコアリングに使用します"
                >
                    <Input
                        type="date"
                        value={data.start_date}
                        onChange={(e) => setData("start_date", e.target.value)}
                        placeholder="2"
                        className={`w-40 ${errors.start_date ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <SectionHeading>契約条件</SectionHeading>

                <FormRow
                    label="単価（月額）"
                    required={fieldSettings.rate.is_required}
                    error={
                        errors.rate_min ?? errors.rate_max ?? errors.rate_note
                    }
                    hint="人材の希望単価が案件の単価レンジ内に収まるかの判定に使用します。スキル見合いの場合はチェックを入れてください。"
                >
                    {/* スキル見合いチェックボックス */}
                    <div className="flex items-center gap-2 mb-2">
                        <Input
                            type="checkbox"
                            id="rate_is_negotiable"
                            checked={data.rate_is_negotiable}
                            onChange={(e) =>
                                handleRateNegotiableChange(e.target.checked)
                            }
                            className="h-4 w-4"
                        />
                        <label
                            htmlFor="rate_is_negotiable"
                            className="text-sm curosr-pointers"
                        >
                            スキル見合い
                        </label>
                    </div>

                    {/* チェックOFF → 下限〜上限の数値欄 */}
                    {!data.rate_is_negotiable && (
                        <div className="flex items-center gap-2">
                            <Input
                                type="number"
                                value={data.rate_min}
                                onChange={(e) =>
                                    setData("rate_min", e.target.value)
                                }
                                placeholder="60"
                                className={`w-28 ${errors.rate_min ? "border-destructive" : ""}`}
                            />
                            <span className="text-sm text-muted-foreground">
                                万円　〜
                            </span>
                            <Input
                                type="number"
                                value={data.rate_max}
                                onChange={(e) =>
                                    setData("rate_max", e.target.value)
                                }
                                placeholder="80"
                                className={`w-28 ${errors.rate_max ? "border-destructive" : ""}`}
                            />
                            <span className="text-sm text-muted-foreground">
                                万円
                            </span>
                        </div>
                    )}

                    {/* チェックON → フリーテキスト欄 */}
                    {data.rate_is_negotiable && (
                        <Input
                            type="text"
                            value={data.rate_note}
                            onChange={(e) =>
                                setData("rate_note", e.target.value)
                            }
                            placeholder="例：スキル見合い、応相談"
                            className={`w-64 ${errors.rate_note ? "border-destructive" : ""}`}
                        />
                    )}
                </FormRow>

                <FormRow
                    label="商流"
                    required={fieldSettings.commercial_flow.is_required}
                    error={errors.commercial_flow}
                >
                    <Select
                        value={data.commercial_flow}
                        onValueChange={(value) =>
                            setData("commercial_flow", value)
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="選択してください" />
                        </SelectTrigger>
                        <SelectContent>
                            {commercial_flows.map((flow) => (
                                <SelectItem key={flow.value} value={flow.value}>
                                    {flow.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormRow>

                <SectionHeading>勤務条件</SectionHeading>

                <FormRow
                    label="稼働形態"
                    required={fieldSettings.work_style.is_required}
                    hint="人材の勤務形態希望とのマッチングスコアリングに使用します"
                    error={errors.work_style}
                >
                    <Select
                        value={data.work_style}
                        onValueChange={(value) => {
                            setData("work_style", value);
                            if (value === "remote") {
                                setData("work_location_line", "");
                                setData("work_location_station", "");
                            }
                        }}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="選択してください" />
                        </SelectTrigger>
                        <SelectContent>
                            {work_styles.map((style) => (
                                <SelectItem key={style.key} value={style.key}>
                                    {style.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormRow>

                {data.work_style !== "" && data.work_style !== "remote" && (
                    <FormRow
                        label="勤務地（最寄駅）"
                        required={fieldSettings.work_location.is_required}
                        hint="フリーテキスト入力。稼働形態が「常駐」または「一部リモート可」の場合は必須です"
                        error={
                            errors.work_location_line ??
                            errors.work_location_station
                        }
                    >
                        <div className="flex gap-2">
                            <Input
                                type="text"
                                value={data.work_location_line}
                                onChange={(e) =>
                                    setData(
                                        "work_location_line",
                                        e.target.value,
                                    )
                                }
                                placeholder="路線名（例：東京メトロ丸ノ内線）"
                                className={`w-56 ${errors.work_location_line ? "border-destructive" : ""}`}
                            />
                            <Input
                                type="text"
                                value={data.work_location_station}
                                onChange={(e) =>
                                    setData(
                                        "work_location_station",
                                        e.target.value,
                                    )
                                }
                                placeholder="駅名（例：大手町）"
                                className={`w-40 ${errors.work_location_station ? "border-destructive" : ""}`}
                            />
                        </div>
                    </FormRow>
                )}

                <FormRow
                    label="面談回数"
                    required={fieldSettings.interview_count.is_required}
                    error={errors.interview_count}
                >
                    <div className="flex items-center gap-2">
                        <Input
                            type="number"
                            value={data.interview_count}
                            onChange={(e) =>
                                setData("interview_count", e.target.value)
                            }
                            placeholder="2"
                            className={`w-20 ${errors.interview_count ? "border-destructive" : ""}`}
                        />
                        <span className="text-sm text-muted-foreground">
                            回
                        </span>
                    </div>
                </FormRow>

                <SectionHeading>スキル要件</SectionHeading>

                <FormRow
                    label="必須スキル"
                    required={fieldSettings.required_skills.is_required}
                    error={errors.required_skills as string | undefined}
                >
                    <SkillInput
                        skills={data.required_skills}
                        onChange={(skills: SkillPair[]) =>
                            setData("required_skills", skills)
                        }
                        error={errors.required_skills as string | undefined}
                    />
                </FormRow>

                <FormRow
                    label="尚可スキル"
                    required={fieldSettings.preferred_skills.is_required}
                    error={errors.preferred_skills as string | undefined}
                >
                    <SkillInput
                        skills={data.preferred_skills}
                        onChange={(skills: SkillPair[]) =>
                            setData("preferred_skills", skills)
                        }
                        error={errors.preferred_skills as string | undefined}
                    />
                </FormRow>

                <FormRow
                    label="対象工程"
                    required={fieldSettings.proc_experience.is_required}
                    error={errors.proc_requirements as string | undefined}
                >
                    <ProcessCheckboxGroup
                        phases={phases}
                        values={procValues}
                        onChange={(key, checked) =>
                            setData(key as keyof typeof data, checked)
                        }
                    />
                </FormRow>

                <FormRow
                    label="顧客折衝経験"
                    required={fieldSettings.negotiation_required.is_required}
                    error={errors.negotiation_required as string | undefined}
                >
                    <Select
                        value={data.negotiation_required ? "true" : "false"}
                        onValueChange={(value) =>
                            setData("negotiation_required", value === "true")
                        }
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {NEGOTIATION_OPTIONS.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormRow>

                <FormRow
                    label="業務内容詳細"
                    required={fieldSettings.description.is_required}
                    error={errors.description}
                    hint="AIが案件内容を理解してマッチング精度を向上させるために使用します"
                >
                    <Textarea
                        value={data.description}
                        onChange={(e) => setData("description", e.target.value)}
                        placeholder="例：大手金融機関の勘定系システムをJava/Spring Bootでリプレースするプロジェクトです。要件定義フェーズから参画いただき、基本設計〜開発・テストまで一貫して担当していただきます。チームは5名構成、スクラム開発を採用しています。"
                        className={`min-h-40 ${errors.description ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <FormRow
                    label="稼働環境"
                    required={fieldSettings.work_env.is_required}
                    error={errors.work_env}
                    hint="OS・ツール・ミドルウェア等の技術環境をフリーテキストで記述してください"
                >
                    <Textarea
                        value={data.work_env}
                        onChange={(e) => setData("work_env", e.target.value)}
                        placeholder="例：OS: CentOS / Windows Server　DBMS: PostgreSQL / SQL Server　開発言語: PHP / JavaScript　クラウド: AWS　その他: Docker / Git"
                        className={`min-h-28 ${errors.work_env ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <SectionHeading>管理情報</SectionHeading>

                <FormRow label="ステータス" required error={errors.status}>
                    <Select
                        value={data.status}
                        onValueChange={(value) => setData("status", value)}
                    >
                        <SelectTrigger className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statuses.map((status) => (
                                <SelectItem
                                    key={status.value}
                                    value={status.value}
                                >
                                    {status.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormRow>

                <FormRow
                    label="担当営業"
                    required
                    error={errors.main_user_id as string | undefined}
                    hint="担当・サブともにユーザーマスタより選択します"
                >
                    {/* 担当（メイン） */}
                    <div className="flex items-center gap-2">
                        <span className="text-sm text-muted-foreground shrink-0">
                            担当
                        </span>
                        <Select
                            value={data.main_user_id}
                            onValueChange={(value) =>
                                setData("main_user_id", value)
                            }
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {users.map((user) => (
                                    <SelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        {/* サブ */}
                        <span className="text-sm text-muted-foreground shrink-0">
                            サブ
                        </span>
                        <Select
                            value={data.sub_user_id}
                            onValueChange={(value) =>
                                setData("sub_user_id", value)
                            }
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="（なし）" />
                            </SelectTrigger>
                            <SelectContent>
                                {users.map((user) => (
                                    <SelectItem
                                        key={user.id}
                                        value={String(user.id)}
                                    >
                                        {user.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </FormRow>

                <SectionHeading>就業条件</SectionHeading>

                <FormRow
                    label="精算幅"
                    required={fieldSettings.billing_range.is_required}
                    error={errors.billing_range}
                    hint="月の精算時間帯をフリーテキストで入力してください"
                >
                    <Input
                        type="text"
                        value={data.billing_range}
                        onChange={(e) =>
                            setData("billing_range", e.target.value)
                        }
                        placeholder="例：140〜180h"
                        className={`w-48 ${errors.billing_range ? "border-destructive" : ""}`}
                    />
                </FormRow>

                <FormRow
                    label="特記事項"
                    required={fieldSettings.remarks.is_required}
                    error={errors.remarks}
                    hint="スコア計算には使用しません。営業担当者が把握しておきたい就業条件を自由記述してください"
                >
                    <Textarea
                        value={data.remarks}
                        onChange={(e) => setData("remarks", e.target.value)}
                        placeholder="例：基本勤務時間 10:00〜19:00、シフト制なし、出張なし など"
                        className={`min-h-28 ${errors.remarks ? "border-destructive" : ""}`}
                    />
                </FormRow>
            </div>
        </AuthenticatedLayout>
    );
}
