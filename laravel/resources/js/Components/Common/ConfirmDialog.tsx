import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

interface Props {
    /** 表示状態 */
    open: boolean;
    /** 見出し（例：「削除しますか？」） */
    title: string;
    /** 本文（任意。氏名などを強調表示できるよう ReactNode を受ける） */
    description?: React.ReactNode;
    /** 実行ボタンのラベル（既定：削除する） */
    confirmLabel?: string;
    /** キャンセルボタンのラベル（既定：キャンセル） */
    cancelLabel?: string;
    /** 実行ボタンの見た目（既定：destructive＝不可逆な削除操作） */
    confirmVariant?: 'destructive' | 'default';
    /** 処理中：両ボタンを無効化し、実行ラベルを processingLabel に差し替える */
    processing?: boolean;
    /** 処理中の実行ラベル（例：削除中...） */
    processingLabel?: string;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * 不可逆操作（削除・終了ステータスへの移動など）の確認モーダル。
 * コンポーネント設計書「汎用表示・操作」の ConfirmDialog に対応。
 * 人材詳細の削除確認と同一の見た目・挙動を全画面で共有するための共通部品。
 *
 * shadcn `Dialog`（Radix ベース）で実装し、フォーカストラップ・Esc で閉じる・aria-modal・
 * Portal 描画（クリック可能要素の内側から呼んでも親へ伝播しない）を標準機能に委ねる。
 * オーバーレイクリック・Esc・×ボタンでの閉じる操作はいずれもキャンセル（onCancel）として扱う。
 */
export default function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel = '削除する',
    cancelLabel = 'キャンセル',
    confirmVariant = 'destructive',
    processing = false,
    processingLabel,
    onConfirm,
    onCancel,
}: Props) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                // 閉じる方向（Esc・オーバーレイ・×）はキャンセル扱い
                if (!next) onCancel();
            }}
        >
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    {/* shadcn 既定（text-lg）は大きめのため、従来の確認モーダルに合わせて text-base に抑える */}
                    <DialogTitle className="text-base font-bold">{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" onClick={onCancel} disabled={processing}>
                        {cancelLabel}
                    </Button>
                    <Button variant={confirmVariant} onClick={onConfirm} disabled={processing}>
                        {processing && processingLabel ? processingLabel : confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
