import EngineerCsvPanel from '@/Components/Csv/EngineerCsvPanel';
import ProjectCsvPanel from '@/Components/Csv/ProjectCsvPanel';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { CsvIndexPageProps } from '@/types/csv';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Props = PageProps<CsvIndexPageProps>;

type CsvTab = 'engineers' | 'projects';

/**
 * CSV入出力画面（WF_11 / api/08 #1）。
 *
 * 人材CSV / 案件CSV の2タブ。タブ見た目はマスタ管理・進捗管理と同じ下線型（TabItem）に統一する
 * （件数バッジは出さない）。両パネルは常時マウントしたまま非アクティブ側を hidden にし、
 * 結果 state をタブ毎に独立保持する（タブ切替で相手タブの結果を消さない／モーダルは切替時に閉じる＝requirements §4）。
 */
export default function CsvIndex({
    engineer_filter_options,
    project_filter_options,
    csv_max_upload_bytes,
}: Props) {
    const [tab, setTab] = useState<CsvTab>('engineers');

    // 地色（bg-muted/30）は <main> に載せる。本文側に置くと内容が短いときに
    // 画面下部が <main> の白背景のまま残るため（issue #82）。
    return (
        <AuthenticatedLayout mainClassName="bg-muted/30">
            <Head title="CSV入出力" />

            {/* マスタ管理／進捗管理と同構造：フルブリードのヘッダー＋タブ＋本文。
                スクロール境界は AuthenticatedLayout の <main> 1か所に一本化し、ヘッダー＋タブは同じ
                スクロール箱の中で sticky 固定する。固定領域をスクロール箱の外に置くとスクロールバー幅
                （約15px）を本文だけが内側で負担し、左右端が一致しない（issue #82）。
                -m-6 は <main> 内側 div の p-6 を打ち消してフルブリードにするためだけに残す。 */}
            <div className="-m-6">
                {/* ヘッダー（ページ見出し＋タブ）。コンテンツをスクロールしても常時表示。 */}
                <div className="sticky top-0 z-10 bg-white">
                    <div className="border-b border-border px-10 py-4">
                        <h1 className="text-lg font-bold text-foreground">CSV入出力</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            人材・案件データのインポート（登録/更新）とエクスポート（ダウンロード）を行います
                        </p>
                    </div>

                    {/* アンダーライン型タブ（マスタ管理／進捗管理と統一・件数バッジなし）。
                        左右パディングは一覧系共通のガター（px-10）に合わせる（issue #82）。 */}
                    <div className="flex items-end border-b-2 border-border bg-white px-10">
                        <TabItem
                            label="人材CSV"
                            isActive={tab === 'engineers'}
                            onClick={() => setTab('engineers')}
                        />
                        <TabItem
                            label="案件CSV"
                            isActive={tab === 'projects'}
                            onClick={() => setTab('projects')}
                        />
                    </div>
                </div>

                {/* コンテンツエリア。両パネルを常時マウントし、非アクティブ側は hidden で隠す（結果 state を独立保持）。
                    左右ガターは固定領域（px-10）と揃える。 */}
                <div className="px-10 py-8">
                    <div className={cn(tab !== 'engineers' && 'hidden')}>
                        <EngineerCsvPanel
                            options={engineer_filter_options}
                            active={tab === 'engineers'}
                            maxUploadBytes={csv_max_upload_bytes}
                        />
                    </div>
                    <div className={cn(tab !== 'projects' && 'hidden')}>
                        <ProjectCsvPanel
                            options={project_filter_options}
                            active={tab === 'projects'}
                            maxUploadBytes={csv_max_upload_bytes}
                        />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * アンダーライン型タブ見出し（マスタ管理 Pages/Master/Index・進捗管理 PipelineTabHeader の TabItem と同じ見た目）。
 * CSV入出力では件数を表示しないため count は持たない。
 */
function TabItem({
    label,
    isActive,
    onClick,
}: {
    label: string;
    isActive: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                '-mb-0.5 border-b-[3px] px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap transition-colors',
                isActive
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
        </button>
    );
}
