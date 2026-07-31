import EngineerCsvPanel from '@/Components/Csv/EngineerCsvPanel';
import ProjectCsvPanel from '@/Components/Csv/ProjectCsvPanel';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { CsvIndexPageProps } from '@/types/csv';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Props = PageProps<CsvIndexPageProps>;

type CsvTab = 'engineers' | 'projects';

/**
 * CSV入出力画面（WF_11 / api/08 #1）。
 *
 * 人材CSV / 案件CSV の2タブ。各タブはインポート欄（CsvUploader＋実行＋担当者ID凡例＋CSV作成ヒント）と
 * エクスポート絞り込みを持つ。両パネルは forceMount で常設し、結果 state をタブ毎に独立保持する
 * （タブを切り替えても相手タブの結果を消さない／モーダルは切替時に閉じる＝requirements §4）。
 */
export default function CsvIndex({
    engineer_filter_options,
    project_filter_options,
}: Props) {
    const [tab, setTab] = useState<CsvTab>('engineers');

    return (
        <AuthenticatedLayout>
            <Head title="CSV入出力" />

            <div className="mx-auto max-w-5xl">
                {/* ページヘッダー */}
                <div className="mb-4">
                    <h1 className="text-lg font-bold text-foreground">CSV入出力</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        人材・案件データのインポート（登録/更新）とエクスポート（ダウンロード）を行います
                    </p>
                </div>

                <Tabs value={tab} onValueChange={(v) => setTab(v as CsvTab)}>
                    <TabsList>
                        <TabsTrigger value="engineers">人材CSV</TabsTrigger>
                        <TabsTrigger value="projects">案件CSV</TabsTrigger>
                    </TabsList>

                    {/* forceMount で両タブを常設し結果を独立保持。非表示側は Radix が hidden にする。 */}
                    <TabsContent value="engineers" forceMount className="data-[state=inactive]:hidden">
                        <EngineerCsvPanel
                            options={engineer_filter_options}
                            active={tab === 'engineers'}
                        />
                    </TabsContent>
                    <TabsContent value="projects" forceMount className="data-[state=inactive]:hidden">
                        <ProjectCsvPanel
                            options={project_filter_options}
                            active={tab === 'projects'}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </AuthenticatedLayout>
    );
}
