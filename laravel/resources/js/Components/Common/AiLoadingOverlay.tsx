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
    /**
     * 中央に出す文言。
     * 本コンポーネントはマッチング計算・職務要約生成など複数の AI 処理で共用するため、
     * 既定は用途を限定しない汎用フォールバックとし、呼び出し側が用途別の具体文言を渡す
     * （例：マッチング＝「AIがマッチングを計算しています…」／職務要約＝「AIが職務要約を生成しています…」）。
     */
    message?: string;
    /**
     * キャンセルハンドラ（任意・オプトイン）。渡された場合のみキャンセルボタンを描画する。
     *
     * ⚠️ クライアント側キャンセルは「クライアントの待ち」を止めるだけで、サーバーの処理は止まらない。
     * そのため **読み取り専用で途中終了しても副作用が残らない処理でのみ渡すこと**（例：マッチング＝DB保存なしの
     * Inertia GET は安全）。**書き込みを伴う処理では渡さない**（例：職務要約生成は保存フロー内でサーバーが
     * ai_summary を永続化するため、クライアントでキャンセルしてもサーバーは登録を完了させ、UI と実データが
     * 不整合になる）。安全性の判断は用途を知る呼び出し側に委ねる。
     */
    onCancel?: () => void;
    /** キャンセルボタンのラベル（既定「キャンセル」）。 */
    cancelLabel?: string;
}

/**
 * AI 処理中の全画面ローディングオーバーレイ（コンポーネント設計書 WF_09）。
 *
 * shadcn/Radix の AlertDialog（割り込みモーダル）を土台にする。Dialog と違い ✕ クローズを持たず、
 * 外側クリックでも閉じないため「勝手に閉じられないローディング」に素直に合致する。加えて
 * フォーカストラップ・背景スクロールロック・aria-modal・Portal を標準で得られる（手組み div より a11y が堅い）。
 *
 * 見た目（明るいブラー背景・中央カード・紫 AI バッジ・スピナー・任意のキャンセル）は従来を踏襲するため、
 * ✕込みの AlertDialogContent は使わず、AlertDialogOverlay を明色で上書きしつつ Content を自前で組む。
 *
 * マッチング実行では、遷移元（Engineers/Show）で router 遷移の onStart→onFinish に合わせて show を制御する。
 */
export default function AiLoadingOverlay({
    show,
    message = 'AIが処理しています…',
    onCancel,
    cancelLabel = 'キャンセル',
}: Props) {
    return (
        // open を props で制御する（トリガーなし）。show=false で Radix が Portal ごとアンマウントする。
        <AlertDialog open={show}>
            <AlertDialogPortal>
                {/* 既定の暗い overlay（bg-black/80）を、従来の明るいブラーに上書きする。 */}
                <AlertDialogOverlay className="bg-white/70 backdrop-blur-sm" />
                <AlertDialogPrimitive.Content
                    aria-busy
                    // 読み取り処理でキャンセル可能なときのみ ESC でキャンセルへ接続。
                    // open は show で制御しているため、常に preventDefault して Radix 側の自動クローズは無効化する。
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
                    <div className="flex items-center gap-2">
                        {/* AI バッジ色は PipelineDrawer・人材詳細・マッチングドロワーと統一（紫） */}
                        <span className="rounded-sm bg-purple-600 px-1.5 py-px text-[10px] font-bold text-white">
                            AI
                        </span>
                        <Loader2 className="h-5 w-5 animate-spin text-purple-600" />
                    </div>
                    {/* 可視メッセージを AlertDialogTitle 兼用にする（Radix のタイトル必須要件も満たす）。 */}
                    <AlertDialogTitle className="text-sm font-semibold text-foreground">
                        {message}
                    </AlertDialogTitle>
                    <AlertDialogDescription className="text-xs text-muted-foreground">
                        しばらくお待ちください
                    </AlertDialogDescription>
                    {/* onCancel が渡された用途（＝キャンセルしても副作用が残らない読み取り処理）でのみ表示する。 */}
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
