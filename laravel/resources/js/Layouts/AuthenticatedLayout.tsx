import { Toaster } from '@/Components/ui/toaster';
import AppSidebar from '@/Components/Navigation/AppSidebar';
import { useToast } from '@/hooks/use-toast';
import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { PropsWithChildren, ReactNode, useEffect } from 'react';

export default function Authenticated({
    header,
    /**
     * スクロール領域（<main>）に足すクラス。地色の切り替えに使う。
     * 参照系の画面（詳細など）は bg-muted/30、入力系（登録・編集）は既定の白のまま。
     * ※一覧・進捗管理は -m-6 で <main> の padding を打ち消し自前のスクロール領域を持つため、
     *   そちら側で背景を指定している。
     */
    mainClassName,
    children,
}: PropsWithChildren<{ header?: ReactNode; mainClassName?: string }>) {
    const page = usePage<PageProps>();
    const { toast } = useToast();

    useEffect(() => {
        const showFlash = (flash?: PageProps['flash']) => {
            if (!flash) return;
            if (flash.success) {
                toast({ description: flash.success, variant: 'success', duration: 3000 });
            }
            if (flash.error) {
                toast({ description: flash.error, variant: 'destructive', duration: 5000 });
            }
        };

        // 初回描画時（POST 後のリダイレクトで最初に表示された場合など）の flash。
        showFlash(page.props.flash);

        // 以降の遷移では success イベントで検知する。
        // useEffect の依存に flash 文字列を使うと、同一メッセージが連続した際に
        // 値が変わらず再表示されない（例：同じユーザーを続けて更新）ため、
        // Inertia の成功レスポンスごとに確実に表示する。
        const offSuccess = router.on('success', (event) => {
            showFlash((event.detail.page.props as unknown as PageProps).flash);
        });

        // サーバーに到達できなかったリクエストを全画面共通で可視化する（#84）。
        //
        // Inertia の visit オプション `onError` は「サーバーが検証エラー（props.errors）を返した」ときだけ
        // 発火する（@inertiajs/core `Response#handle`）。そのため通信断・オフラインのように
        // レスポンス自体が得られない失敗は `onError` にも flash にも乗らず、ローディング表示が
        // 消えるだけでユーザーには何も伝わらない（Silent Rejection）。この経路は
        // `exception` イベントに流れるため（同 `Request#send` の catch）、ここで一元的に拾う。
        //
        // - キャンセルは `axios.isCancel` で早期 return されるため、このリスナーには届かない
        //   （オーバーレイのキャンセル操作で誤ってエラーが出ることはない）
        // - サーバーに到達した非 Inertia レスポンス（500 等）は `invalid` → Inertia のエラーモーダルで
        //   可視化されるため、ここでは扱わない（二重通知にならない）
        // - リスナーは値を返さない。`false` を返すと Inertia 既定の再スローまで抑止され、
        //   例外がコンソール・エラー監視に残らなくなるため
        // - 現状プレフェッチ（`prefetch` / `WhenVisible`）は未使用。導入する場合は、ユーザーが
        //   起こしていない背景リクエストの失敗までトーストになるため、この購読の見直しが必要
        const offException = router.on('exception', () => {
            toast({
                // `exception` は通信断のほかレスポンス処理中の JS 例外でも発火するため、
                // 原因を断定しない文言にする。
                description: '処理に失敗しました。通信環境をご確認のうえ、再度お試しください。',
                variant: 'destructive',
                duration: 5000,
            });
        });

        return () => {
            offSuccess();
            offException();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        // レイヤー① 最外殻: 100vh で固定し、外へ溢れさせない
        <div className="flex h-screen overflow-hidden bg-background">
            <AppSidebar />

            {/*
             * レイヤー②: WF の .main-content に対応。
             * ★ overflow-hidden 必須 ★
             *   flex-col の中間コンテナにこれがないと、<main> が
             *   min-height:auto（コンテンツ高さ）まで膨らんで
             *   親を突き破り window スクロールが発生する。
             */}
            <div className="flex flex-1 flex-col overflow-hidden">
                {header && (
                    <header className="shrink-0 border-b border-border bg-white px-6 py-4">
                        <h2 className="text-lg font-semibold text-foreground">
                            {header}
                        </h2>
                    </header>
                )}

                {/*
                 * レイヤー③ スクロール境界: ここだけ overflow-y-auto。
                 * padding を <main> 自身ではなく内側 div に置くことで
                 * sticky top-0 の吸着位置が正しく top:0 になる。
                 */}
                <main className={cn('flex-1 overflow-y-auto', mainClassName)}>
                    <div className="p-6">{children}</div>
                </main>
            </div>
            <Toaster />
        </div>
    );
}