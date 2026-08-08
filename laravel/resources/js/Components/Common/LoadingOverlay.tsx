import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { cn } from '@/lib/utils';
import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import { Loader2 } from 'lucide-react';

interface Props {
    /** 表示フラグ。false のときは何も描画しない（Radix が Portal ごとアンマウントする）。 */
    show: boolean;
    /** 中央に出す文言（用途別に呼び出し側が渡す。例：「CSVを取り込んでいます…」）。 */
    message?: string;
    /** 補足文言（既定「しばらくお待ちください」）。不要なら空文字を渡す。 */
    description?: string;
    /**
     * キャンセルハンドラ（任意・オプトイン）。渡された場合のみキャンセルボタンを描画する。
     *
     * ⚠️ クライアント側キャンセルは「クライアントの待ち」を止めるだけで、サーバー処理は止まらない。
     * 書き込みを伴う処理（CSV 取り込み等）では渡さないこと（UI と実データが不整合になる）。
     */
    onCancel?: () => void;
    /** キャンセルボタンのラベル（既定「キャンセル」）。 */
    cancelLabel?: string;
}

/**
 * 汎用ローディングオーバーレイ（用途中立・AI バッジなし）。
 *
 * `Common/AiLoadingOverlay` の Radix AlertDialog 土台（✕ を持たず・外側クリックで閉じない・
 * 明色ブラー幕・フォーカストラップ・背景スクロールロック・aria-modal）を汎用化したもの。
 * AI 専用の紫バッジは持たず、中立のスピナーのみ表示する。
 * CSV 取り込みなど「勝手に閉じられない待機表示」で使う。
 *
 * ※ AiLoadingOverlay は AI 用途（紫バッジ）専用として別に維持する（改変しない）。
 */
export default function LoadingOverlay({
    show,
    message = '処理しています…',
    description = 'しばらくお待ちください',
    onCancel,
    cancelLabel = 'キャンセル',
}: Props) {
    return (
        // open を props で制御する（トリガーなし）。show=false で Radix が Portal ごとアンマウントする。
        <AlertDialog open={show}>
            <AlertDialogPortal>
                {/* 既定の暗い overlay（bg-black/80）を、プロジェクト既定の明色ブラーに上書きする。 */}
                <AlertDialogOverlay className="bg-white/70 backdrop-blur-sm" />
                <AlertDialogPrimitive.Content
                    aria-busy
                    // onCancel が渡された用途でのみ ESC をキャンセルに接続。
                    // open は show で制御するため、常に preventDefault して Radix の自動クローズは無効化する。
                    onEscapeKeyDown={(e) => {
                        e.preventDefault();
                        onCancel?.();
                    }}
                    className={cn(
                        'fixed left-1/2 top-1/2 z-50 flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-3',
                        'rounded-lg border border-border bg-white px-8 py-6 shadow-xl',
                        'duration-200 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95',
                        'data-[state=closed]:animate-out data-[state=closed]:fade-out-0',
                    )}
                >
                    {/* 中立スピナー（AI バッジは付けない）。 */}
                    <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                    {/* 可視メッセージを AlertDialogTitle 兼用にする（Radix のタイトル必須要件も満たす）。 */}
                    <AlertDialogTitle className="text-sm font-semibold text-foreground">
                        {message}
                    </AlertDialogTitle>
                    {description !== '' && (
                        <AlertDialogDescription className="text-xs text-muted-foreground">
                            {description}
                        </AlertDialogDescription>
                    )}
                    {onCancel && (
                        <AlertDialogCancel onClick={onCancel} className="mt-1 h-8 px-3 text-xs">
                            {cancelLabel}
                        </AlertDialogCancel>
                    )}
                </AlertDialogPrimitive.Content>
            </AlertDialogPortal>
        </AlertDialog>
    );
}
