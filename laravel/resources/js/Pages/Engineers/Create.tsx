import ProcessCheckboxGroup from '@/Components/Engineers/ProcessCheckboxGroup';
import SkillInput from '@/Components/Engineers/SkillInput';
import WorkStyleCheckboxGroup from '@/Components/Engineers/WorkStyleCheckboxGroup';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerCreatePageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useMemo } from 'react';

type Props = PageProps<EngineerCreatePageProps>;

type FormData = {
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

// ---- layout helpers (inline) ----

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

// ---- page component ----

export default function Create({ fieldSettings, phases, work_styles, statuses, users }: Props) {
    const form = useForm<FormData>({
        name: '',
        name_kana: '',
        birth_date: '',
        nearest_line: '',
        nearest_station: '',
        available_from: '',
        skills: [],
        proc_requirements: false,
        proc_basic_design: false,
        proc_detail_design: false,
        proc_development: false,
        proc_testing: false,
        proc_maintenance: false,
        has_negotiation_exp: '',
        appeal_note: '',
        desired_rate: '',
        work_styles: [],
        remarks: '',
        status: 'proposable',
        main_user_id: '',
        sub_user_id: '',
    });

    const { data, setData, errors, processing } = form;

    const calculatedAge = useMemo(() => {
        if (!data.birth_date) return null;
        const birth = new Date(data.birth_date);
        if (isNaN(birth.getTime())) return null;
        const today = new Date();
        let age = today.getFullYear() - birth.getFullYear();
        const m = today.getMonth() - birth.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
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

    const handleSubmit = () => {
        form.transform((d) => ({
            ...d,
            has_negotiation_exp:
                d.has_negotiation_exp === '1'
                    ? true
                    : d.has_negotiation_exp === '0'
                      ? false
                      : null,
            desired_rate: d.desired_rate !== '' ? Number(d.desired_rate) : null,
            main_user_id: d.main_user_id !== '' ? Number(d.main_user_id) : null,
            sub_user_id: d.sub_user_id !== '' ? Number(d.sub_user_id) : null,
            birth_date: d.birth_date || null,
            available_from: d.available_from || null,
            appeal_note: d.appeal_note || null,
            remarks: d.remarks || null,
        }));
        form.post('/engineers', {
            onError: () => {
                requestAnimationFrame(() => {
                    document.querySelector('.text-destructive')?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center',
                    });
                });
            },
        });
    };

    return (
        <AuthenticatedLayout>
            <Head title="人材登録" />
            {/* Sticky page header */}
            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">人材登録</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        新規人材情報を登録します
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.get('/engineers')}
                    >
                        キャンセル
                    </Button>
                    <Button type="button" onClick={handleSubmit} disabled={processing}>
                        {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {processing ? '保存中...' : '保存する'}
                    </Button>
                </div>
            </div>

            {/* Form */}
            <div className="max-w-3xl">

                {/* ==================== 基本情報 ==================== */}
                <SectionHeading>基本情報</SectionHeading>

                <FormRow
                    label="氏名 / カナ"
                    required
                    error={errors.name || errors.name_kana}
                >
                    <div className="flex gap-2">
                        <Input
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="例：山田 太郎"
                            className={errors.name ? 'border-destructive' : ''}
                        />
                        <Input
                            value={data.name_kana}
                            onChange={(e) => setData('name_kana', e.target.value)}
                            placeholder="例：ヤマダ タロウ"
                            className={errors.name_kana ? 'border-destructive' : ''}
                        />
                    </div>
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

                <FormRow
                    label="最寄駅 / 路線"
                    required={fieldSettings.nearest_station.is_required || fieldSettings.nearest_line.is_required}
                    hint="出社が必要な案件との通勤条件の判定に使用します。駅名・路線名をそれぞれ自由入力（例：新宿 / JR中央線）"
                    error={errors.nearest_station || errors.nearest_line}
                >
                    <div className="flex gap-2">
                        <Input
                            value={data.nearest_station}
                            onChange={(e) => setData('nearest_station', e.target.value)}
                            placeholder="駅名（例：新宿）"
                            className="w-48"
                        />
                        <Input
                            value={data.nearest_line}
                            onChange={(e) => setData('nearest_line', e.target.value)}
                            placeholder="路線名（例：JR中央線）"
                            className="w-48"
                        />
                    </div>
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
                    />
                </FormRow>

                <FormRow
                    label="経験工程"
                    required={fieldSettings.proc_experience.is_required}
                >
                    <ProcessCheckboxGroup
                        phases={phases}
                        values={procValues}
                        onChange={(key, checked) =>
                            setData(key as keyof FormData, checked)
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
                            setData('has_negotiation_exp', v as FormData['has_negotiation_exp'])
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
                                "__none__" をセンチネルにし、onValueChange で "" に戻す。送信時は handleSubmit で null に変換される。 */}
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
        </AuthenticatedLayout>
    );
}
