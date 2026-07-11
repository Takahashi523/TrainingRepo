import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import { cn } from '@/lib/utils';
import { PipelineStatus, StatusOption } from '@/types/pipeline';
import { useState } from 'react';

interface Props {
    /** 現在のステータス値 */
    value: PipelineStatus;
    /** 進行中12種＋終了4種の全選択肢（is_terminal で終了判定） */
    statusOptions: StatusOption[];
    /**
     * 変更確定時に呼ばれる。終了ステータスへの変更は不可逆のため、
     * confirm を通過した場合のみ呼び出す（キャンセル時は発火しない）。
     */
    onChange: (value: PipelineStatus) => void;
    /** card：カンバンカード用（全幅） / drawer：ドロワー用（flex-1） */
    variant?: 'card' | 'drawer';
    className?: string;
    /** カード本体クリック（ドロワー展開）への伝播を止めたい場合 true */
    stopPropagation?: boolean;
    /**
     * 終了ステータス選択時に本コンポーネント内で確認ダイアログを出すか（既定 true）。
     * ドロワーは「保存する」時にまとめて確認するため false を渡し、選択時はフォーム反映のみとする。
     */
    confirmTerminal?: boolean;
}

const TERMINAL_CONFIRM_MESSAGE =
    'このステータスに変更すると完了済みタブへ移動し、元に戻せません。';

/**
 * ステータス変更プルダウン（カンバンカード／ドロワー共通）。
 * 進行中・終了をグループで分け、終了ステータス選択時は不可逆である旨を confirm で警告する。
 *
 * コンポーネント設計書 §1-3 の「Select は shadcn に完全委任」に準拠し、shadcn `Select`
 * （Radix ベース）で実装する。進行中／終了のグループ分けは shadcn の
 * `SelectGroup` + `SelectLabel` + `SelectSeparator` で表現する。
 */
export default function StatusSelect({
    value,
    statusOptions,
    onChange,
    variant = 'card',
    className,
    stopPropagation = false,
    confirmTerminal = true,
}: Props) {
    const inProgressOptions = statusOptions.filter((o) => !o.is_terminal);
    const terminalOptions = statusOptions.filter((o) => o.is_terminal);

    // 終了ステータスへの変更は不可逆（完了済みタブへ移動）のため、確認ダイアログ待ちの値を保持する
    const [pendingTerminal, setPendingTerminal] = useState<string | null>(null);

    const handleChange = (next: string) => {
        if (next === value) return;
        const opt = statusOptions.find((o) => o.value === next);
        // 終了ステータスは共通 ConfirmDialog で確認してから確定する（QA #64）。
        // confirmTerminal=false（ドロワー）の場合は選択時の確認は行わず、呼び出し側（保存時）に委ねる。
        if (opt?.is_terminal && confirmTerminal) {
            setPendingTerminal(next);
            return;
        }
        onChange(next as PipelineStatus);
    };

    return (
        <>
        <Select value={value} onValueChange={handleChange}>
            {/* カード内ではトリガークリック／キー操作をカード本体（ドロワー展開）へ伝播させない */}
            <SelectTrigger
                onClick={stopPropagation ? (e) => e.stopPropagation() : undefined}
                onKeyDown={stopPropagation ? (e) => e.stopPropagation() : undefined}
                className={cn(
                    'h-8 text-xs',
                    variant === 'card' ? 'w-full' : 'w-auto min-w-0 flex-1',
                    className,
                )}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    <SelectLabel className="text-[11px] text-muted-foreground">進行中</SelectLabel>
                    {inProgressOptions.map((o) => (
                        <SelectItem key={o.value} value={o.value} className="text-xs">
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
                <SelectSeparator />
                <SelectGroup>
                    <SelectLabel className="text-[11px] text-muted-foreground">
                        終了（完了済みタブへ）
                    </SelectLabel>
                    {terminalOptions.map((o) => (
                        <SelectItem key={o.value} value={o.value} className="text-xs">
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>

        {/* 終了ステータスへの変更（＝完了済みタブへの移動）確認。共通 ConfirmDialog で統一 */}
        <ConfirmDialog
            open={pendingTerminal !== null}
            title="完了済みへ移動しますか？"
            description={TERMINAL_CONFIRM_MESSAGE}
            confirmLabel="移動する"
            confirmVariant="default"
            onConfirm={() => {
                if (pendingTerminal) onChange(pendingTerminal as PipelineStatus);
                setPendingTerminal(null);
            }}
            onCancel={() => setPendingTerminal(null)}
        />
        </>
    );
}
