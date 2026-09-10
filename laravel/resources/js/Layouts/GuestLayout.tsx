import { Toaster } from '@/Components/ui/toaster';
import { useConnectionErrorToast } from '@/hooks/use-connection-error-toast';
import { PropsWithChildren } from 'react';

/**
 * ゲスト（未認証）画面の共通レイアウト。
 * WF_01 に合わせ、中央にログインカード1枚を配置する。
 * ブランド表示（Nexus ワードマーク）はカード内の先頭に置くため、各ページ側で描画する。
 */
export default function Guest({ children }: PropsWithChildren) {
    // 通信断の通知は認証済み画面だけでなくログイン（`useForm().post`）でも必要なため、
    // AuthenticatedLayout と同じ購読をここでも行う（#84）。購読はトーストを出すだけなので
    // 描画先の <Toaster /> が要る。flash の購読は認証済み画面の関心事なので持ち込まない。
    useConnectionErrorToast();

    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-gray-100 px-4 py-6">
            <div className="w-full max-w-[400px] rounded-lg border border-gray-200 bg-white px-9 py-10 shadow-sm">
                {children}
            </div>
            <Toaster />
        </div>
    );
}
