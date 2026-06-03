import AppSidebar from '@/Components/Navigation/AppSidebar';
import { PropsWithChildren, ReactNode } from 'react';

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
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
        </div>
    );
}