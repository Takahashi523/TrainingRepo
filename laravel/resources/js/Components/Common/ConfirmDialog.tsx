import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { Button } from '@/Components/ui/button';
import { useRef } from 'react';

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
 * **不可逆操作（削除・終了ステータスへの移動など）専用**の確認モーダル。
 * コンポーネント設計書「汎用表示・操作」の ConfirmDialog に対応。
 * 人材詳細の削除確認と同一の見た目・挙動を全画面で共有するための共通部品。
 *
 * shadcn `AlertDialog`（Radix ベース）で実装し、role="alertdialog"・フォーカストラップ・
 * Esc で閉じる・aria-modal・Portal 描画（クリック可能要素の内側から呼んでも親へ伝播しない）を
 * 標準機能に委ねる。Radix の AlertDialog は開いたときにキャンセルへ初期フォーカスを当てるため、
 * Enter 連打による誤実行を防げる。
 *
 * ⚠️ **可逆な確認には使わないこと。** 本部品は「背景クリックで閉じない・✕ を持たない」＝
 * 明示的な応答を要求する設計であり、これは対象が不可逆操作であることを前提にしている。
 * 取り消せる操作の確認には shadcn `Dialog` を使う（背景クリックで閉じられてよいため）。
 *
 * 閉じる導線は Esc とキャンセルボタンで、いずれも onCancel として扱う。
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
    // ダイアログを開く直前にフォーカスされていた要素（＝削除ボタン等の呼び出し元）。
    // 閉じたときにここへフォーカスを戻すため保持する。詳細は onCloseAutoFocus のコメント。
    const previouslyFocused = useRef<HTMLElement | null>(null);

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                // 閉じる方向（Esc・キャンセルボタン）はキャンセル扱い。
                // キャンセルボタン側に onClick を持たせず、閉じる経路をここ 1 本に集約する
                // （両方に書くと onCancel が二重に呼ばれる）。
                if (!next) onCancel();
            }}
        >
            {/* 背景幕は AI ローディングオーバーレイ（AiLoadingOverlay）と同じ明色ブラーに揃え、
                アプリ全体でモーダル背景の見た目を統一する（既定の暗幕 bg-black/80 を上書き） */}
            <AlertDialogContent
                className="max-w-sm"
                overlayClassName="bg-white/70 backdrop-blur-sm"
                // 処理中は両ボタンを disabled にしているため、Esc だけが通って
                // リクエスト実行中に閉じてしまうことがないようにする。
                onEscapeKeyDown={(event) => {
                    if (processing) event.preventDefault();
                }}
                // 開く直前のフォーカス位置を控える。Radix はこのハンドラを
                // 「フォーカスをダイアログ内へ移す前」に呼ぶため、ここでの activeElement は
                // まだ呼び出し元（削除ボタン等）を指している。
                // preventDefault はしない（キャンセルへの初期フォーカスは Radix に任せる）。
                onOpenAutoFocus={() => {
                    previouslyFocused.current =
                        document.activeElement as HTMLElement | null;
                }}
                // 閉じたときのフォーカス復帰を自前で行う。
                // Radix の Dialog は既定で「preventDefault → Trigger へフォーカス」する実装だが、
                // 本アプリは open を親の state で制御し AlertDialogTrigger を使わないため
                // triggerRef が null になり、復帰先が無いままフォーカスが body へ落ちてしまう。
                // （キーボード操作時に Tab がページ先頭からやり直しになる）
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    previouslyFocused.current?.focus();
                }}
            >
                <AlertDialogHeader>
                    {/* shadcn 既定（text-lg）は大きめのため、従来の確認モーダルに合わせて text-base に抑える */}
                    <AlertDialogTitle className="text-base font-bold">{title}</AlertDialogTitle>
                    {description && (
                        // 人材名・案件名・ユーザー名など可変長の文字列を差し込むため、
                        // 長い語で横に溢れないよう共通部品側で折り返しを効かせる。
                        <AlertDialogDescription className="break-words">
                            {description}
                        </AlertDialogDescription>
                    )}
                </AlertDialogHeader>
                <AlertDialogFooter>
                    {/* Radix は開いたときに AlertDialogCancel へ初期フォーカスを当てる（cancelRef）。
                        素の Button のままだとこの既定フォーカスが得られないため asChild で包む。 */}
                    <AlertDialogCancel asChild>
                        <Button variant="outline" disabled={processing}>
                            {cancelLabel}
                        </Button>
                    </AlertDialogCancel>
                    {/* 実行側は AlertDialogAction を使わない。Action は押下で必ず閉じるため、
                        「実行後も開いたまま processing を表示し、親が完了時に閉じる」現行の設計
                        （＝二重送信防止と「削除中...」表示）が壊れる。加えて open を親が制御しているため、
                        Action だと onOpenChange(false) が走り onConfirm と onCancel が同時に発火する。 */}
                    <Button variant={confirmVariant} onClick={onConfirm} disabled={processing}>
                        {processing && processingLabel ? processingLabel : confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
