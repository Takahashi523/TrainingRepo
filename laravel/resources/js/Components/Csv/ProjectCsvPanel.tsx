import CsvImportSection from '@/Components/Csv/CsvImportSection';
import ExportFilter, { ExportFilterConfig } from '@/Components/Csv/ExportFilter';
import { Card } from '@/Components/ui/card';
import { CsvFilterOptions } from '@/types/csv';

interface Props {
    options: CsvFilterOptions;
    /** このタブが表示中か（モーダルのタブ切替クローズ制御に使う）。 */
    active: boolean;
}

/**
 * 案件CSVタブ（インポート欄＋エクスポート絞り込み）。
 * 結果 state は CsvImportSection 内に閉じ、タブ毎に独立保持される（人材タブと相互に消さない）。
 *
 * エクスポートのクエリパラメータ名は ProjectCsvExportRequest に厳密一致：
 *   status[] / user_id / start_date_from / start_date_to / keyword / work_style[]
 */
const exportConfig: ExportFilterConfig = {
    exportRouteName: 'csv.projects.export',
    dateLabel: '参画開始時期',
    dateFromParam: 'start_date_from',
    dateToParam: 'start_date_to',
    keywordLabel: '必須スキル（キーワード）',
    keywordPlaceholder: '例：PHP, Laravel',
    workStyleLabel: '稼働形態',
    workStyleParam: 'work_style',
};

export default function ProjectCsvPanel({ options, active }: Props) {
    return (
        <div className="space-y-6">
            <CsvImportSection
                resource="projects"
                importRouteName="csv.projects.import"
                resourceLabel="案件"
                users={options.users}
                active={active}
            />

            <Card className="overflow-hidden">
                <div className="border-b border-border bg-muted/40 px-5 py-3">
                    <h2 className="text-sm font-bold text-foreground">
                        エクスポート（ダウンロード）
                    </h2>
                </div>
                <div className="p-5">
                    <ExportFilter options={options} config={exportConfig} />
                </div>
            </Card>
        </div>
    );
}
