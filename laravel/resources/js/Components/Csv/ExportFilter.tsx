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
const ALL_STATUS = 'all';

/**
 * エクスポート絞り込み UI（WF_11）。
 *
 * エクスポートは Inertia を介さない GET ダウンロード（StreamedResponse）。
 * 絞り込み条件をクエリ文字列にして `window.location.assign` で遷移する（画面遷移はせずファイルDLになる）。
 * 配列パラメータは `status[]` / `work_styles[]` 形式で Laravel の配列バリデーションに合わせる。
 */
export default function ExportFilter({ options, config }: Props) {
    // ステータスは単一選択（WF_11 準拠）。バックエンドは status[] 配列のため、選択時のみ1件を配列で送る。
    const [status, setStatus] = useState<string | null>(null);
    const [userId, setUserId] = useState<number | null>(null);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [keyword, setKeyword] = useState('');
    const [workStyles, setWorkStyles] = useState<string[]>([]);
    // 日付逆転（開始 > 終了）のクライアント側ガード用エラー。サーバーの after_or_equal を最後の砦に残しつつ、
    // 素の GET ダウンロード（window.location.assign）では 422 を画面に出せないため、送信前にここで弾く。
    const [dateError, setDateError] = useState<string | null>(null);

    const toggleWorkStyle = (key: string) => {
        setWorkStyles((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );
    };

    const handleExport = () => {
        // 開始・終了ともに指定があり、開始 > 終了なら送信を止めて即時にエラー表示する（ISO 文字列は辞書順比較で日付順と一致）。
        if (dateFrom !== '' && dateTo !== '' && dateFrom > dateTo) {
            setDateError(`${config.dateLabel}は開始日を終了日以前にしてください。`);
            return;
        }
        setDateError(null);

        const params = new URLSearchParams();
        // 単一選択だがサーバーの status[] 配列バリデーションに合わせ、選択時のみ1件を配列で送る。
        if (status !== null) params.append('status[]', status);
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
                絞り込み条件を指定して、対象データを CSV でダウンロードします。<br />
                条件を指定しない場合は全件を出力します。<br />
                対象が0件の場合もヘッダー行のみの CSV をダウンロードできます（初期投入テンプレートとして利用可）。
            </p>

            <p className="text-[11px] font-bold text-muted-foreground">
                絞り込み条件（任意）
            </p>

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {/* ステータス（単一・WF_11 準拠） */}
                <div className="flex flex-col gap-1.5">
                    <Label className="text-[11px] font-bold text-muted-foreground">
                        ステータス
                    </Label>
                    <Select
                        value={status === null ? ALL_STATUS : status}
                        onValueChange={(v) => setStatus(v === ALL_STATUS ? null : v)}
                    >
                        <SelectTrigger className="h-8 bg-white text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL_STATUS} className="text-xs">
                                すべて
                            </SelectItem>
                            {options.statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value} className="text-xs">
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
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
                                全担当
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
                            onChange={(e) => {
                                setDateFrom(e.target.value);
                                setDateError(null);
                            }}
                            className="h-8 bg-white px-2 text-xs md:text-xs"
                            aria-label={`${config.dateLabel}（開始）`}
                        />
                        <span className="shrink-0 text-[11px] text-muted-foreground">〜</span>
                        <Input
                            type="date"
                            value={dateTo}
                            onChange={(e) => {
                                setDateTo(e.target.value);
                                setDateError(null);
                            }}
                            className="h-8 bg-white px-2 text-xs md:text-xs"
                            aria-label={`${config.dateLabel}（終了）`}
                        />
                    </div>
                    {/* エラー表示は人材登録フォーム（FormRow）と同じ text-xs / text-destructive に揃える。 */}
                    {dateError && (
                        <p className="text-xs text-destructive">{dateError}</p>
                    )}
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
                        maxLength={15}
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
