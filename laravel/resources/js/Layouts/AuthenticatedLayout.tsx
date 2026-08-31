import { Toaster } from '@/Components/ui/toaster';
import AppSidebar from '@/Components/Navigation/AppSidebar';
import { useConnectionErrorToast } from '@/hooks/use-connection-error-toast';
import { useToast } from '@/hooks/use-toast';
import { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { PropsWithChildren, ReactNode, Ref, useEffect } from 'react';

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
    /**
     * スクロール領域（<main>）への ref。一覧のページ送りで先頭へ戻すために、
     * ページ側が `useScrollContainer()` で作った ref を受け取る（issue #107）。
     *
     * ※ ref を「ページが持ち、レイアウトへ渡す」向きにしているのは、逆向き（レイアウトが
     *   context で配る）が成立しないため。ページは <AuthenticatedLayout> を子として描画する側
     *   ＝ Provider の親であり、自分の子孫が提供する context は受け取れない。
     */
    mainRef,
    children,
}: PropsWithChildren<{
    header?: ReactNode;
    mainClassName?: string;
    mainRef?: Ref<HTMLElement>;
}>) {
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
                 *
                 * この要素は mainRef 経由でページ側にも渡り、一覧のページ送りで先頭へ戻す対象になる
                 * （issue #107）。Inertia のスクロールリセットは window と scroll-region 属性の要素しか
                 * 触らないため、ここは自前で戻す必要がある。
                 *
                 * ⚠️ ここに scroll-region 属性を足さないこと。属性を足すと preserveScroll: true の
                 *    visit（一覧のフィルタ・ページ送り）で Inertia が swap 後の rAF に元の位置を
                 *    復元するようになり、ページ送りの先頭復帰が巻き戻される。
                 */}
                <main ref={mainRef} className={cn('flex-1 overflow-y-auto', mainClassName)}>
                    {/*
                     * この p-6（24px）が既定の左右ガター。詳細・登録・編集はこれをそのまま使い、
                     * sticky ページヘッダだけが -mx-6 でフルブリード化したうえで px-10 を当てている
                     * （＝ヘッダ 40px / 本文 24px）。
                     * 一方、一覧系は -m-6 で p-6 を打ち消し、ヘッダ・フィルタ・本文をすべて px-10 に
                     * 揃えている（issue #82）。ガター値が一覧系と詳細系で異なるのは #82 のスコープが
                     * 一覧系だったためで、統一の是非は別途判断する。
                     */}
                    <div className="p-6">{children}</div>
                </main>
            </div>
            <Toaster />
        </div>
    );
}