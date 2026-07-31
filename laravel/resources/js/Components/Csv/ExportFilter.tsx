import MultiSelectDropdown, {
    MultiSelectOption,
} from '@/Components/Common/MultiSelectDropdown';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { CsvFilterOptions } from '@/types/csv';
import { Download } from 'lucide-react';
import { useState } from 'react';

/**
 * エクスポート絞り込みの設定（人材・案件の差＝クエリパラメータ名とラベルを外から注入）。
 * パラメータ名は Export Request クラス（EngineerCsvExportRequest / ProjectCsvExportRequest）に厳密に一致させる。
 */
export interface ExportFilterConfig {
    /** Ziggy ルート名（例：'csv.engineers.export'）。 */
    exportRouteName: string;
    /** 日付範囲のラベル（人材：稼働可能時期／案件：参画開始時期）。 */
    dateLabel: string;
    /** 日付 From/To のパラメータ名（人材：available_from_start/end／案件：start_date_from/to）。 */
    dateFromParam: string;
    dateToParam: string;
    /** キーワード欄のラベル。 */
    keywordLabel: string;
    /** キーワード欄のプレースホルダ。 */
    keywordPlaceholder: string;
    /** 勤務形態のラベル（人材：勤務形態タグ／案件：稼働形態）。 */
    workStyleLabel: string;
    /** 勤務形態のパラメータ名（人材：work_styles／案件：work_style）。 */
    workStyleParam: string;
}

interface Props {
    options: CsvFilterOptions;
    config: ExportFilterConfig;
}

const ALL_USERS = 'all';

/**
 * エクスポート絞り込み UI（WF_11）。
 *
 * エクスポートは Inertia を介さない GET ダウンロード（StreamedResponse）。
 * 絞り込み条件をクエリ文字列にして `window.location.assign` で遷移する（画面遷移はせずファイルDLになる）。
 * 配列パラメータは `status[]` / `work_styles[]` 形式で Laravel の配列バリデーションに合わせる。
 */
export default function ExportFilter({ options, config }: Props) {
    const [status, setStatus] = useState<string[]>([]);
    const [userId, setUserId] = useState<number | null>(null);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [keyword, setKeyword] = useState('');
    const [workStyles, setWorkStyles] = useState<string[]>([]);

    const statusOptions: MultiSelectOption[] = options.statuses.map((s) => ({
        value: s.value,
        label: s.label,
    }));

    const toggleWorkStyle = (key: string) => {
        setWorkStyles((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );
    };

    const handleExport = () => {
        const params = new URLSearchParams();
        status.forEach((v) => params.append('status[]', v));
        if (userId !== null) params.append('user_id', String(userId));
        if (dateFrom) params.append(config.dateFromParam, dateFrom);
        if (dateTo) params.append(config.dateToParam, dateTo);
        const kw = keyword.trim();
        if (kw !== '') params.append('keyword', kw);
        workStyles.forEach((v) => params.append(`${config.workStyleParam}[]`, v));

        const base = route(config.exportRouteName);
        const qs = params.toString();
        // GET ダウンロード（Content-Disposition: attachment）。Inertia を介さない通常遷移。
        window.location.assign(qs === '' ? base : `${base}?${qs}`);
    };

    return (
        <div className="space-y-4">
            <p className="text-xs leading-relaxed text-muted-foreground">
                絞り込み条件を指定して、対象データを CSV でダウンロードします。条件を指定しない場合は全件を出力します。
                対象が0件の場合もヘッダー行のみの CSV をダウンロードできます（初期投入テンプレートとして利用可）。
            </p>

            <p className="text-[11px] font-bold text-muted-foreground">
                絞り込み条件（任意）
            </p>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {/* ステータス（複数） */}
                <div className="flex flex-col gap-1.5">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        ステータス
                    </Label>
                    <div>
                        <MultiSelectDropdown
                            label={status.length === 0 ? 'すべて' : 'ステータス'}
                            options={statusOptions}
                            selected={status}
                            onChange={setStatus}
                        />
                    </div>
                </div>

                {/* 担当営業（単一） */}
                <div className="flex flex-col gap-1.5">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        担当営業
                    </Label>
                    <Select
                        value={userId === null ? ALL_USERS : String(userId)}
                        onValueChange={(v) => setUserId(v === ALL_USERS ? null : Number(v))}
                    >
                        <SelectTrigger className="h-8 bg-white text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent className="max-w-[280px]">
                            <SelectItem value={ALL_USERS} className="text-xs">
                                全員
                            </SelectItem>
                            {options.users.map((u) => (
                                <SelectItem key={u.id} value={String(u.id)} className="text-xs">
                                    <span className="block max-w-[240px] truncate">{u.name}</span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* 日付範囲 */}
                <div className="flex flex-col gap-1.5">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        {config.dateLabel}
                    </Label>
                    <div className="flex items-center gap-2">
                        <Input
                            type="date"
                            value={dateFrom}
                            onChange={(e) => setDateFrom(e.target.value)}
                            className="h-8 bg-white px-2 text-xs md:text-xs"
                            aria-label={`${config.dateLabel}（開始）`}
                        />
                        <span className="shrink-0 text-[11px] text-muted-foreground">〜</span>
                        <Input
                            type="date"
                            value={dateTo}
                            onChange={(e) => setDateTo(e.target.value)}
                            className="h-8 bg-white px-2 text-xs md:text-xs"
                            aria-label={`${config.dateLabel}（終了）`}
                        />
                    </div>
                </div>

                {/* キーワード */}
                <div className="flex flex-col gap-1.5">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        {config.keywordLabel}
                    </Label>
                    <Input
                        type="text"
                        value={keyword}
                        onChange={(e) => setKeyword(e.target.value)}
                        placeholder={config.keywordPlaceholder}
                        maxLength={100}
                        className="h-8 bg-white text-xs md:text-xs"
                    />
                </div>

                {/* 勤務形態（複数チェック） */}
                <div className="flex flex-col gap-1.5 md:col-span-2">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        {config.workStyleLabel}
                    </Label>
                    <div className="flex flex-wrap gap-4 pt-1">
                        {options.work_styles.map((w) => {
                            const checked = workStyles.includes(w.key);
                            return (
                                <label
                                    key={w.key}
                                    className="flex cursor-pointer items-center gap-2 text-xs text-foreground"
                                >
                                    <Checkbox
                                        checked={checked}
                                        onCheckedChange={() => toggleWorkStyle(w.key)}
                                    />
                                    {w.name}
                                </label>
                            );
                        })}
                    </div>
                </div>
            </div>

            <div className="flex justify-end border-t border-border pt-4">
                <Button type="button" onClick={handleExport} className="h-9 gap-1.5">
                    <Download className="h-4 w-4" />
                    エクスポート実行
                </Button>
            </div>
        </div>
    );
}
