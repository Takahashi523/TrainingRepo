import ProcessCheckboxGroup from '@/Components/Common/ProcessCheckboxGroup';
import SkillInput from '@/Components/Common/SkillInput';
import WorkStyleCheckboxGroup from '@/Components/Engineers/WorkStyleCheckboxGroup';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import {
    FieldSettings,
    Phase,
    StatusOption,
    UserOption,
    WorkTypeOption,
} from '@/types/engineer';
import { InertiaFormProps } from '@inertiajs/react';
import { useMemo } from 'react';

export type EngineerFormData = {
    name: string;
    name_kana: string;
    birth_date: string;
    nearest_line: string;
    nearest_station: string;
    available_from: string;
    skills: { label: string; detail: string | null }[];
    proc_requirements: boolean;
    proc_basic_design: boolean;
    proc_detail_design: boolean;
    proc_development: boolean;
    proc_testing: boolean;
    proc_maintenance: boolean;
    has_negotiation_exp: '' | '1' | '0';
    appeal_note: string;
    desired_rate: string;
    work_styles: string[];
    remarks: string;
    status: string;
    main_user_id: string;
    sub_user_id: string;
};

interface Props {
    form: InertiaFormProps<EngineerFormData>;
    fieldSettings: FieldSettings;
    phases: Phase[];
    work_styles: WorkTypeOption[];
    statuses: StatusOption[];
    users: UserOption[];
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-4 mt-9 flex items-center gap-3 [&:first-child]:mt-0">
            <span className="shrink-0 text-sm font-bold text-foreground">{children}</span>
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
                <span className="text-xs font-semibold text-foreground">{label}</span>
            </div>
            <div className="min-w-0 flex-1 space-y-1.5">
                {children}
                {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
                {error && <p className="text-xs text-destructive">{error}</p>}
            </div>
        </div>
    );
}

export default function EngineerForm({
    form,
    fieldSettings,
    phases,
    work_styles,
    statuses,
    users,
}: Props) {
    const { data, setData, errors } = form;

    const calculatedAge = useMemo(() => {
        if (!data.birth_date) return null;
        const birth = new Date(data.birth_date);
        if (isNaN(birth.getTime())) return null;
        const today = new Date();
        const birthdayThisYear = new Date(today.getFullYear(), birth.getMonth(), birth.getDate());
        const age = today.getFullYear() - birth.getFullYear() - (today < birthdayThisYear ? 1 : 0);
        return age;
    }, [data.birth_date]);

    const procValues: Record<string, boolean> = {
        proc_requirements: data.proc_requirements,
        proc_basic_design: data.proc_basic_design,
        proc_detail_design: data.proc_detail_design,
        proc_development: data.proc_development,
        proc_testing: data.proc_testing,
        proc_maintenance: data.proc_maintenance,
    };

    return (
        <div className="max-w-3xl">
            {/* ==================== 基本情報 ==================== */}
            <SectionHeading>基本情報</SectionHeading>

            {/* 氏名・氏名カナは登録フィールドが別のため、他項目と同じく独立した行として並べる。 */}
            <FormRow label="氏名" required error={errors.name}>
                <Input
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="例：山田 太郎"
                    className={`w-64 ${errors.name ? 'border-destructive' : ''}`}
                />
            </FormRow>

            <FormRow label="カナ" required error={errors.name_kana}>
                <Input
                    value={data.name_kana}
                    onChange={(e) => setData('name_kana', e.target.value)}
                    placeholder="例：ヤマダ タロウ"
                    className={`w-64 ${errors.name_kana ? 'border-destructive' : ''}`}
                />
            </FormRow>

            <FormRow
                label="生年月日"
                required={fieldSettings.birth_date.is_required}
                error={errors.birth_date}
            >
                <div className="flex items-center gap-3">
                    <Input
                        type="date"
                        value={data.birth_date}
                        onChange={(e) => setData('birth_date', e.target.value)}
                        className="w-40"
                    />
                    {calculatedAge !== null && (
                        <span className="rounded border border-border bg-muted px-3 py-1.5 text-sm text-muted-foreground">
                            → {calculatedAge}歳（自動計算）
                        </span>
                    )}
                </div>
            </FormRow>

            {/* 最寄駅・路線は登録フィールドが別で必須/任意も独立するため、他項目と同じく
                独立した行（左カラムに必須/任意バッジ）として並べる。 */}
            <FormRow
                label="最寄駅"
                required={fieldSettings.nearest_station.is_required}
                hint="出社が必要な案件との通勤条件の判定に使用します。駅名を自由入力（例：新宿）"
                error={errors.nearest_station}
            >
                <Input
                    value={data.nearest_station}
                    onChange={(e) => setData('nearest_station', e.target.value)}
                    placeholder="駅名（例：新宿）"
                    className={`w-64 ${errors.nearest_station ? 'border-destructive' : ''}`}
                />
            </FormRow>

            <FormRow
                label="路線"
                required={fieldSettings.nearest_line.is_required}
                hint="路線名を自由入力（例：JR中央線）"
                error={errors.nearest_line}
            >
                <Input
                    value={data.nearest_line}
                    onChange={(e) => setData('nearest_line', e.target.value)}
                    placeholder="路線名（例：JR中央線）"
                    className={`w-64 ${errors.nearest_line ? 'border-destructive' : ''}`}
                />
            </FormRow>

            <FormRow
                label="稼働可能時期"
                required={fieldSettings.available_from.is_required}
                error={errors.available_from}
            >
                <div className="flex items-center gap-2">
                    <Input
                        type="date"
                        value={data.available_from}
                        onChange={(e) => setData('available_from', e.target.value)}
                        className="w-40"
                    />
                    <span className="text-sm text-muted-foreground">〜（以降）</span>
                </div>
            </FormRow>

            {/* ==================== スキル情報 ==================== */}
            <SectionHeading>スキル情報</SectionHeading>

            <FormRow
                label="経験スキル"
                required={fieldSettings.skills.is_required}
                error={errors.skills as string | undefined}
            >
                <SkillInput
                    skills={data.skills}
                    onChange={(skills) => setData('skills', skills)}
                    errors={errors as unknown as Record<string, string>}
                />
            </FormRow>

            <FormRow
                label="経験工程"
                required={fieldSettings.proc_experience.is_required}
                error={
                    errors.proc_requirements ||
                    errors.proc_basic_design ||
                    errors.proc_detail_design ||
                    errors.proc_development ||
                    errors.proc_testing ||
                    errors.proc_maintenance
                }
            >
                <ProcessCheckboxGroup
                    phases={phases}
                    values={procValues}
                    onChange={(key, checked) =>
                        setData(key as keyof EngineerFormData, checked)
                    }
                />
            </FormRow>

            <FormRow
                label="顧客折衝経験"
                required={fieldSettings.has_negotiation_exp.is_required}
                hint="顧客との折衝・コミュニケーション経験。案件マッチングの判断基準として使用します"
                error={errors.has_negotiation_exp}
            >
                <Select
                    value={data.has_negotiation_exp}
                    onValueChange={(v) =>
                        setData('has_negotiation_exp', v as EngineerFormData['has_negotiation_exp'])
                    }
                >
                    <SelectTrigger className="w-40">
                        <SelectValue placeholder="選択してください" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="1">有</SelectItem>
                        <SelectItem value="0">無</SelectItem>
                    </SelectContent>
                </Select>
            </FormRow>

            {/* ==================== 経歴・PR ==================== */}
            <SectionHeading>経歴・PR</SectionHeading>

            <FormRow
                label="アピールポイント"
                required={fieldSettings.appeal_note.is_required}
                hint="AIによる職務要約の生成元となります。人材の強み・経験・自己PRを記載してください"
                error={errors.appeal_note}
            >
                <Textarea
                    value={data.appeal_note}
                    onChange={(e) => setData('appeal_note', e.target.value)}
                    placeholder="例：Java・Spring Bootを用いた金融系システムの開発経験が豊富です。チームリードの経験もあり、コミュニケーション能力にも自信があります。"
                    rows={4}
                />
            </FormRow>

            {/* ==================== 希望条件 ==================== */}
            <SectionHeading>希望条件</SectionHeading>

            <FormRow
                label="希望単価（月額）"
                required={fieldSettings.desired_rate.is_required}
                error={errors.desired_rate}
            >
                <div className="flex items-center gap-2">
                    <Input
                        type="number"
                        value={data.desired_rate}
                        onChange={(e) => setData('desired_rate', e.target.value)}
                        placeholder="60"
                        className="w-24"
                        min={0}
                    />
                    <span className="text-sm text-muted-foreground">万円</span>
                </div>
            </FormRow>

            <FormRow
                label="勤務形態"
                required={fieldSettings.work_styles.is_required}
                error={errors.work_styles as string | undefined}
            >
                <WorkStyleCheckboxGroup
                    workTypes={work_styles}
                    selected={data.work_styles}
                    onChange={(selected) => setData('work_styles', selected)}
                />
            </FormRow>

            <FormRow
                label="特記事項"
                required={fieldSettings.remarks.is_required}
                hint="スコア計算には使用しません。担当営業が把握しておきたい条件を自由記述してください"
                error={errors.remarks}
            >
                <Textarea
                    value={data.remarks}
                    onChange={(e) => setData('remarks', e.target.value)}
                    placeholder="例：土日祝休み希望、出張NG、残業月20h以内希望 など"
                    rows={3}
                />
            </FormRow>

            {/* ==================== 管理情報 ==================== */}
            <SectionHeading>管理情報</SectionHeading>

            <FormRow
                label="ステータス"
                required
                hint="人材の現在の状況です。一覧の検索・絞り込みに使用します"
                error={errors.status}
            >
                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                    <SelectTrigger className="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {statuses.map((s) => (
                            <SelectItem key={s.value} value={s.value}>
                                {s.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FormRow>

            <FormRow
                label="担当営業"
                required
                hint="担当・サブともにユーザーマスタより選択します"
                error={errors.main_user_id || errors.sub_user_id}
            >
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">担当</span>
                        <Select
                            value={data.main_user_id}
                            onValueChange={(v) => setData('main_user_id', v)}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="選択してください" />
                            </SelectTrigger>
                            <SelectContent>
                                {users.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="text-xs text-muted-foreground">サブ</span>
                        {/* Radix UI は value="" を「選択なし」として予約しているため SelectItem に空文字を渡せない。
                            "__none__" をセンチネルにし、onValueChange で "" に戻す。送信時は親側 transform で null に変換される。 */}
                        <Select
                            value={data.sub_user_id || '__none__'}
                            onValueChange={(v) => setData('sub_user_id', v === '__none__' ? '' : v)}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="（なし）" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">（なし）</SelectItem>
                                {users.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </FormRow>
        </div>
    );
}
