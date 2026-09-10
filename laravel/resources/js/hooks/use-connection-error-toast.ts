import { useToast } from '@/hooks/use-toast';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * サーバーに到達できなかったリクエストを可視化する購読（#84）。
 *
 * Inertia の visit オプション `onError` は「サーバーが検証エラー（`props.errors`）を返した」ときだけ
 * 発火する（`@inertiajs/core` `Response#handle`）。そのため通信断・オフラインのようにレスポンス自体が
 * 得られない失敗は `onError` にも `flash` にも乗らず、ローディング表示が消えるだけでユーザーには
 * 何も伝わらない（Silent Rejection）。この経路は `exception` イベントに流れるため（同 `Request#send`
 * の catch）、ここで一元的に拾う。
 *
 * ページごとではなく**レイアウトから呼ぶ**。穴は GET だけでなく `useForm().post` 等の書き込み系も
 * 含む全リクエストに共通で開いているため、画面単位の対処では取りこぼす。
 * 呼び出し側のレイアウトには `<Toaster />` が必要。
 *
 * - キャンセルは `axios.isCancel` で早期 return されるため、このリスナーには届かない
 *   （`AiLoadingOverlay` のキャンセル操作で誤ってエラーが出ることはない）
 * - サーバーに到達した非 Inertia レスポンス（500 等）は `invalid` → Inertia のエラーモーダルで
 *   可視化されるため、ここでは扱わない（二重通知にならない）
 * - リスナーは値を返さない。`false` を返すと Inertia 既定の再スローまで抑止され、
 *   例外がコンソール・エラー監視に残らなくなるため
 * - 現状プレフェッチ（`prefetch` / `WhenVisible`）は未使用。導入する場合は、ユーザーが
 *   起こしていない背景リクエストの失敗までトーストになるため、この購読の見直しが必要
 */
export function useConnectionErrorToast() {
    const { toast } = useToast();

    useEffect(() => {
        const offException = router.on('exception', () => {
            toast({
                // `exception` は通信断のほかレスポンス処理中の JS 例外でも発火するため、
                // 原因を断定しない文言にする。
                description: '処理に失敗しました。通信環境をご確認のうえ、再度お試しください。',
                variant: 'destructive',
                duration: 5000,
            });
        });

        return offException;
        // toast はモジュールレベルの関数で同一性が保たれるため、購読は初回マウント時の1回でよい。
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
