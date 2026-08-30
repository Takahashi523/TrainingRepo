import { TooltipBubble, useHoverTooltip } from '@/Components/Common/HoverTooltip';
import InputError from '@/Components/InputError';
import { cn } from '@/lib/utils';
import { formatFileSize, sanitizeFileName } from '@/types/csv';
import { FileText, FolderOpen, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    /** 現在選択中のファイル（未選択は null）。表示は完全にこの prop で制御する。 */
    file: File | null;
    /** ファイルが選択された（D&D またはダイアログ）ときに呼ばれる。 */
    onSelect: (file: File) => void;
    /** ユーザーが「✕ 取り消す」を押したときに呼ばれる。 */
    onClear: () => void;
    /** 送信中など操作を止めたいとき。 */
    disabled?: boolean;
    /** ファイルレベルのエラーメッセージ（mime/サイズ/文字コード/行数超過など）。 */
    errorMessage?: string;
    /** 受け付ける拡張子（既定 .csv）。 */
    accept?: string;
    /** 案内文に出すサイズ上限のラベル（例：5MB）。サイズ判定自体は呼び出し側が行う。 */
    maxSizeLabel?: string;
}

/**
 * CSV アップロード用のドラッグ&ドロップ＋ファイル選択部品（WF_11 の upload-area 準拠）。
 *
 * 部品選定：shadcn/ui にファイルアップロード primitive が無いため自作（CLAUDE.md 部品選定順 ③）。
 * 内部の入力は素の `<input type="file">`。表示は `file` prop で制御し、
 * ファイル名は sanitizeFileName で制御文字・双方向制御文字を除去して JSX で描画する（O-12）。
 * `dangerouslySetInnerHTML` は使わない（React 自動エスケープに委ねる）。
 */
export default function CsvUploader({
    file,
    onSelect,
    onClear,
    disabled = false,
    errorMessage,
    accept = '.csv',
    maxSizeLabel,
}: Props) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragOver, setDragOver] = useState(false);

    // ファイル名の全文（200文字まで）をホバーで出す。
    // TruncatedText を使わないのは、ファイル名の省略が CSS の1行省略ではなく
    // sanitizeFileName による中央省略（head…tail）で、拡張子を残すことに意味があるため。
    // TruncatedText は scrollWidth > clientWidth でしか省略を検知できず、この中央省略を拾えない。
    // ネイティブ title を使わない理由は HoverTooltip と同じ（遅延・見た目が OS 依存で揃わない）。
    const fileNameTooltip = useHoverTooltip<HTMLSpanElement>({ delay: 150 });

    // file が null に戻ったら input の値もクリアし、「同じファイルを選び直す」で change が再発火するようにする。
    // （<input type="file"> は value を "" 以外に設定できないため、選択状態は file prop を SSOT にする）
    useEffect(() => {
        if (file === null && inputRef.current) {
            inputRef.current.value = '';
        }
    }, [file]);

    const openDialog = () => {
        if (disabled) return;
        inputRef.current?.click();
    };

    const handleFiles = (files: FileList | null) => {
        const picked = files?.[0];
        if (picked) onSelect(picked);
    };

    if (file !== null) {
        // ── 選択後表示（ファイル名・サイズ・取消） ──
        return (
            <div>
                <div className="flex items-center gap-2 rounded-md border border-border bg-muted/50 px-3.5 py-2 text-sm text-foreground">
                    <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                    {/* サニタイズ済みのファイル名を JSX で描画（自動エスケープ）。長い名前は省略し、
                        全文はホバーのツールチップで確認できるようにする（省略したら全文手段を必ず添える）。 */}
                    <span
                        ref={fileNameTooltip.ref}
                        className="min-w-0 truncate font-semibold"
                        {...fileNameTooltip.triggerProps}
                    >
                        {sanitizeFileName(file.name)}
                    </span>
                    {fileNameTooltip.anchor && (
                        <TooltipBubble
                            text={sanitizeFileName(file.name, 200)}
                            anchor={fileNameTooltip.anchor}
                        />
                    )}
                    <span className="shrink-0 text-xs text-muted-foreground">
                        （{formatFileSize(file.size)}）
                    </span>
                    <button
                        type="button"
                        onClick={onClear}
                        disabled={disabled}
                        className="ml-auto flex shrink-0 items-center gap-1 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground disabled:opacity-50"
                    >
                        <X className="h-3 w-3" />
                        取り消す
                    </button>
                </div>
                {errorMessage && <InputError message={errorMessage} className="mt-1.5" />}
            </div>
        );
    }

    // ── ドラッグ&ドロップ / クリック選択エリア ──
    return (
        <div>
            <div
                role="button"
                tabIndex={0}
                aria-disabled={disabled}
                onClick={openDialog}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        openDialog();
                    }
                }}
                onDragOver={(e) => {
                    e.preventDefault();
                    if (!disabled) setDragOver(true);
                }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragOver(false);
                    if (!disabled) handleFiles(e.dataTransfer.files);
                }}
                className={cn(
                    'flex flex-col items-center rounded-md border-2 border-dashed px-6 py-9 text-center transition-colors',
                    disabled
                        ? 'cursor-not-allowed border-border bg-muted/30 opacity-60'
                        // 操作系コントロールのため、hover/focus/click(active) の操作状態は primary で示す。
                        : 'cursor-pointer border-input bg-muted/30 hover:border-primary hover:bg-primary/5 focus-visible:border-primary focus-visible:bg-primary/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 active:border-primary active:bg-primary/10',
                    // ドラッグ中（ドロップ可能な状態）も操作中として primary で強調する。
                    dragOver && !disabled && 'border-primary bg-primary/5',
                )}
            >
                <FolderOpen className="mb-2.5 h-7 w-7 text-muted-foreground" />
                <p className="text-sm font-semibold text-foreground">
                    CSVファイルをドラッグ＆ドロップ、またはクリックして選択
                </p>
                <p className="mt-2 text-[11px] leading-relaxed text-muted-foreground">
                    対応形式：.csv（UTF-8 / BOM付き）
                    {maxSizeLabel && `　最大ファイルサイズ：${maxSizeLabel}`}
                    {/* 地の文で書くと JSX が行頭の全角スペース(U+3000)もトリムし前項目と密着するため、JS 文字列で保持する。 */}
                    {'　改行コード：CRLF・LF両対応'}
                </p>
                <button
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        openDialog();
                    }}
                    disabled={disabled}
                    className="mt-3.5 inline-flex h-8 items-center rounded-md border border-input bg-background px-4 text-xs font-semibold text-foreground hover:bg-accent disabled:opacity-50"
                >
                    ファイルを選択
                </button>
                <input
                    ref={inputRef}
                    type="file"
                    accept={accept}
                    className="hidden"
                    onChange={(e) => handleFiles(e.target.files)}
                    disabled={disabled}
                />
            </div>
            {errorMessage && <InputError message={errorMessage} className="mt-1.5" />}
        </div>
    );
}
