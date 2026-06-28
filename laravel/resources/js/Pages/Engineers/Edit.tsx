import EngineerForm, { EngineerFormData } from '@/Components/Engineers/EngineerForm';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Engineer, EngineerEditPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';

type Props = PageProps<EngineerEditPageProps>;

function toFormData(engineer: Engineer): EngineerFormData {
    const procMap = Object.fromEntries(
        engineer.phases.map((p) => [p.key, p.has_experience])
    );

    return {
        name:                engineer.name,
        name_kana:           engineer.name_kana,
        birth_date:          engineer.birth_date ? engineer.birth_date.slice(0, 10) : '',
        nearest_line:        engineer.nearest_line ?? '',
        nearest_station:     engineer.nearest_station ?? '',
        available_from:      engineer.available_from ? engineer.available_from.slice(0, 10) : '',
        skills:              engineer.skills.map((s) => ({ label: s.label ?? '', detail: s.detail })),
        proc_requirements:   procMap.proc_requirements  ?? false,
        proc_basic_design:   procMap.proc_basic_design  ?? false,
        proc_detail_design:  procMap.proc_detail_design ?? false,
        proc_development:    procMap.proc_development   ?? false,
        proc_testing:        procMap.proc_testing       ?? false,
        proc_maintenance:    procMap.proc_maintenance   ?? false,
        has_negotiation_exp: engineer.has_negotiation_exp === true
            ? '1'
            : engineer.has_negotiation_exp === false
              ? '0'
              : '',
        appeal_note:  engineer.appeal_note ?? '',
        desired_rate: engineer.desired_rate != null ? String(engineer.desired_rate) : '',
        work_styles:  engineer.work_styles.map((w) => w.key),
        remarks:      engineer.remarks ?? '',
        status:       engineer.status,
        main_user_id: String(engineer.users.main.id),
        sub_user_id:  engineer.users.sub ? String(engineer.users.sub.id) : '',
    };
}

export default function Edit({ engineer, fieldSettings, phases, work_styles, statuses, users }: Props) {
    const form = useForm<EngineerFormData>(toFormData(engineer));

    const { processing } = form;

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
        form.put(`/engineers/${engineer.id}`, {
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
            <Head title="人材編集" />
            {/* Sticky page header */}
            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">人材編集</h1>
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
                    <Button type="button" onClick={handleSubmit} disabled={processing}>
                        {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {processing ? '保存中...' : '保存する'}
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
        </AuthenticatedLayout>
    );
}
