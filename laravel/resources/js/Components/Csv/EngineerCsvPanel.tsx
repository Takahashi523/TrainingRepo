import CsvImportSection from '@/Components/Csv/CsvImportSection';
import ExportFilter, { ExportFilterConfig } from '@/Components/Csv/ExportFilter';
import { Card } from '@/Components/ui/card';
import { CsvFilterOptions } from '@/types/csv';

interface Props {
    options: CsvFilterOptions;
    /** このタブが表示中か（モーダルのタブ切替クローズ制御に使う）。 */
    active: boolean;
    /** アップロード上限（バイト・サーバー由来）。インポートのサイズ事前ガードに渡す。 */
    maxUploadBytes: number;
}

/**
 * 人材CSVタブ（インポート欄＋エクスポート絞り込み）。
 * 結果 state は CsvImportSection 内に閉じ、タブ毎に独立保持される（案件タブと相互に消さない）。
 *
 * エクスポートのクエリパラメータ名は EngineerCsvExportRequest に厳密一致：
 *   status[] / user_id / available_from_start / available_from_end / keyword / work_styles[]
 */
const exportConfig: ExportFilterConfig = {
    exportRouteName: 'csv.engineers.export',
    dateLabel: '稼働可能時期',
    dateFromParam: 'available_from_start',
    dateToParam: 'available_from_end',
    keywordLabel: 'スキル（キーワード）',
    keywordPlaceholder: '例：Java',
    workStyleLabel: '勤務形態タグ',
    workStyleParam: 'work_styles',
};

export default function EngineerCsvPanel({ options, active, maxUploadBytes }: Props) {
    return (
        <div className="space-y-8">
            <CsvImportSection
                resource="engineers"
                importRouteName="csv.engineers.import"
                resourceLabel="人材"
                users={options.users}
                active={active}
                maxUploadBytes={maxUploadBytes}
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
