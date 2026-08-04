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

/** Content-Disposition ヘッダーからサーバー生成のファイル名を取り出す（取得できなければ既定名）。 */
function filenameFromDisposition(disposition: string | null): string {
    const match = disposition?.match(/filename="?([^";]+)"?/i);
    return match?.[1] ?? 'export.csv';
}

/**
 * エクスポート絞り込み UI（WF_11）。
 *
 * エクスポートは Inertia を介さない GET ダウンロード（StreamedResponse）。fetch でファイル本体を取得し、
 * Blob 経由で `<a download>` を発火させる（同一オリジンの Cookie は自動付与）。
 * こうすることで、絞り込み条件が実行時に不正（例：選択後に削除された担当者IDで exists 失敗）になり
 * サーバーが 422 を返しても、SPA を離脱して生の JSON エラーページを表示せず、画面内にエラーを出せる。
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
    // 送信前にここで弾いて即時フィードバックする。
    const [dateError, setDateError] = useState<string | null>(null);
    // ダウンロード全体のエラー（422・通信失敗など）。日付ガードとは別に、実行ボタン付近へ表示する。
    const [exportError, setExportError] = useState<string | null>(null);
    // ダウンロード中フラグ（二重実行防止・ボタン無効化）。
    const [exporting, setExporting] = useState(false);

    const toggleWorkStyle = (key: string) => {
        setWorkStyles((prev) =>
            prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
        );
    };

    const handleExport = async () => {
        if (exporting) return;

        // 開始・終了ともに指定があり、開始 > 終了なら送信を止めて即時にエラー表示する（ISO 文字列は辞書順比較で日付順と一致）。
        if (dateFrom !== '' && dateTo !== '' && dateFrom > dateTo) {
            setDateError(`${config.dateLabel}は開始日を終了日以前にしてください。`);
            return;
        }
        setDateError(null);
        setExportError(null);

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
        const url = qs === '' ? base : `${base}?${qs}`;

        setExporting(true);
        try {
            // same-origin のため Cookie（セッション）は自動付与される。GET なので CSRF トークンは不要。
            const res = await fetch(url, {
                headers: { Accept: 'text/csv' },
                credentials: 'same-origin',
            });

            if (!res.ok) {
                // 422＝絞り込み条件が実行時に不正（削除済み担当者ID等）。SPA を保ったままインライン表示する。
                setExportError(
                    res.status === 422
                        ? '絞り込み条件が正しくありません。画面を再読み込みして条件を選び直してください。'
                        : 'エクスポートに失敗しました。時間をおいて再度お試しください。',
                );
                return;
            }

            // 本体を Blob 化し、サーバー生成のファイル名で <a download> を発火する（画面遷移なし）。
            const blob = await res.blob();
            const objectUrl = window.URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = filenameFromDisposition(res.headers.get('Content-Disposition'));
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(objectUrl);
        } catch {
            // ネットワーク断など。握りつぶさずインラインで知らせる。
            setExportError('エクスポートに失敗しました。通信環境を確認して再度お試しください。');
        } finally {
            setExporting(false);
        }
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

            <div className="flex items-center justify-end gap-3 border-t border-border pt-4">
                {/* ダウンロード全体のエラー（422・通信失敗）。日付ガードとは別枠でボタン左に出す。 */}
                {exportError && (
                    <p className="text-xs text-destructive">{exportError}</p>
                )}
                <Button
                    type="button"
                    onClick={handleExport}
                    disabled={exporting}
                    className="h-9 gap-1.5"
                >
                    <Download className="h-4 w-4" />
                    {exporting ? 'ダウンロード中…' : 'エクスポート実行'}
                </Button>
            </div>
        </div>
    );
}
