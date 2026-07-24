import { Toaster } from '@/Components/ui/toaster';
import AppSidebar from '@/Components/Navigation/AppSidebar';
import { useToast } from '@/hooks/use-toast';
import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode, useEffect } from 'react';

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
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
        return router.on('success', (event) => {
            showFlash((event.detail.page.props as unknown as PageProps).flash);
        });
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
                <main className="flex-1 overflow-y-auto">
                    <div className="p-6">{children}</div>
                </main>
            </div>
            <Toaster />
        </div>
    );
}