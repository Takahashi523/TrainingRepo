import { Toaster } from '@/Components/ui/toaster';
import AppSidebar from '@/Components/Navigation/AppSidebar';
import { useConnectionErrorToast } from '@/hooks/use-connection-error-toast';
import { useToast } from '@/hooks/use-toast';
import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { PropsWithChildren, ReactNode, useEffect } from 'react';

export default function Authenticated({
    header,
    /**
     * スクロール領域（<main>）に足すクラス。地色の切り替えに使う。
     * 参照系の画面（詳細・一覧など）は bg-muted/30、入力系（登録・編集）は既定の白のまま。
     *
     * ※ 一覧系（人材一覧・案件一覧・進捗管理の完了済みタブ・ダッシュボード・マスタ管理・CSV入出力）も
     *   ここで地色を指定する。これらは -m-6 で <main> の padding を打ち消しつつ、スクロールは
     *   <main> に任せて固定領域を sticky top-0 で留める構成のため（issue #82）。
     *   本文側に bg を置くと内容が短いとき（0件・少件数）に下部が <main> の白背景のまま残る。
     *   ＝ この Props を外すと 0件時に白帯が露出するので、冗長に見えても外さないこと。
     *
     * ※ 例外は進捗管理の進行中タブ（カンバン）とマッチング結果の2画面。どちらもドロワーの
     *   位置決めやカンバンの確定高さのために自前の全高コンテナを持ち、地色もそちら側で指定する。
     */
    mainClassName,
    children,
}: PropsWithChildren<{ header?: ReactNode; mainClassName?: string }>) {
    const page = usePage<PageProps>();
    const { toast } = useToast();

    // 通信断（サーバーに到達できない失敗）の通知。GuestLayout でも同じ購読を行う（#84）。
    useConnectionErrorToast();

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

        return offSuccess;
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