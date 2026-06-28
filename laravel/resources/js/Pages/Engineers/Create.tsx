import EngineerForm, { EngineerFormData } from '@/Components/Engineers/EngineerForm';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerCreatePageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';

type Props = PageProps<EngineerCreatePageProps>;

export default function Create({ fieldSettings, phases, work_styles, statuses, users }: Props) {
    const form = useForm<EngineerFormData>({
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
