import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import GuestLayout from '@/Layouts/GuestLayout';
import { cn } from '@/lib/utils';
import { User } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertTriangle, SearchX, ShieldAlert } from 'lucide-react';
import { ComponentType, ReactNode } from 'react';

/**
 * 共通エラーページ（issue #70）。
 *
 * 404 / 403 / 500 / 503 で、フレームワーク既定の素の画面ではなくアプリの体裁を保った案内を出す
 * （サーバー側の差し込みは app/Exceptions/ErrorPageResponder）。
 * 419（セッション切れ）はここへ来ない。再ログインが必要なためログイン画面へ送っている。
 */

/** 404 の原因。サーバーが入口（未定義 URL / レコード無し）で区別して渡す */
type ErrorReason = 'missing_page' | 'missing_resource';

type ErrorPageProps = {
    status: number;
    reason: ErrorReason | null;
};

/**
 * この画面は未認証でも描画されうる唯一のページのため、共有 Props の auth をここだけ nullable として受ける。
 * グローバルの PageProps（auth.user: User 前提）は変更しない。認証必須ルートでは user が必ず存在し、
 * そちらを nullable にすると全画面が不要な null チェックを強いられるため。
 */
type ErrorSharedProps = {
    auth?: { user: User | null };
};

type ErrorContent = {
    icon: ComponentType<{ className?: string }>;
    title: string;
    description: string;
};

/**
 * 表示内容はキー引きで解決し、コンポーネント本体に分岐を持たせない
 * （マッチング結果の空状態 Pages/Matching/Show.tsx の EMPTY_STATES と同じ構成）。
 * 404 のみ、原因によって文言が変わるため 2 種類を持つ。
 */
const ERROR_CONTENTS: Record<string, ErrorContent> = {
    // 未定義 URL。打ち間違い・古いブックマークが原因のため、まず URL の確認を促す。
    '404:missing_page': {
        icon: SearchX,
        title: 'アクセスしようとしたページが見つかりません',
        description:
            'URL に誤りがないかご確認ください。ブックマークから開いた場合は、リンク先が古い可能性があります。',
    },
    // URL は正しいが対象レコードが無い（削除済み・ID 違い）。
    // 「別のレコードを指している」といった内部事情ではなく、利用者が取れる行動（一覧の確認）で締める。
    '404:missing_resource': {
        icon: SearchX,
        title: '対象が見つかりません',
        description:
            '既に削除されたか、URL の指定に誤りがある可能性があります。一覧をご確認ください。',
    },
    // 405：Route::fallback() が GET / HEAD 専用のため、未定義 URL への POST / DELETE がここに来る
    //（古いタブからの送信・URL 打ち間違い）。定義済み URL へのメソッド違いも同じ扱いでよい。
    '405': {
        icon: SearchX,
        title: 'この操作は実行できませんでした',
        description:
            'ページを開いてから内容が変わった可能性があります。開き直してからもう一度お試しください。',
    },
    '403': {
        icon: ShieldAlert,
        title: 'このページを表示する権限がありません',
        description:
            '管理者のみが利用できる機能です。必要な場合は管理者にご連絡ください。',
    },
    // 問い合わせ窓口は docs/ に定義が無いため案内しない（実在が保証されない連絡先を書かない）。
    // また本アプリの「管理者」は admin ロールのユーザーを指し、アプリ障害の窓口ではない。
    '500': {
        icon: AlertTriangle,
        title: 'システムエラーが発生しました',
        description: '時間をおいて再度お試しください。',
    },
    '503': {
        icon: AlertTriangle,
        title: 'ただいまメンテナンス中です',
        description: '時間をおいて再度アクセスしてください。',
    },
    // 現行の許可リスト（ErrorPageResponder::HANDLED_STATUSES）からは到達しない保険。
    // 許可リストに status を追加して文言の追加を忘れても、素の白ページに戻らないようにする。
    default: {
        icon: AlertTriangle,
        title: 'エラーが発生しました',
        description: '時間をおいて再度お試しください。',
    },
};

/**
 * reason が未知・欠落のときの 404 は missing_resource を既定にする。
 * missing_page は「URL 自体が存在しない」と断定する文言のため、確証が無いときには出さない。
 */
function resolveContent(status: number, reason: ErrorReason | null): ErrorContent {
    if (status === 404) {
        const key = reason === 'missing_page' ? '404:missing_page' : '404:missing_resource';

        return ERROR_CONTENTS[key];
    }

    return ERROR_CONTENTS[String(status)] ?? ERROR_CONTENTS.default;
}

export default function ErrorPage({ status, reason }: ErrorPageProps) {
    // 未ログインのときは auth.user に null が入る。usePage の型引数は PageProps（認証済み前提）に
    // 制約されるため、ここでは実際の値に合わせた型で受け直す。
    const user = (usePage().props as ErrorSharedProps).auth?.user ?? null;

    const content = resolveContent(status, reason);
    const Icon = content.icon;

    return (
        <>
            <Head title={content.title} />
            {renderWithLayout(
                <ErrorBody
                    content={content}
                    icon={Icon}
                    guest={!user}
                    // メンテナンス中はログイン画面も 503 を返すため、押しても同じ画面に戻る導線は出さない。
                    showLoginLink={!user && status !== 503}
                />,
                user,
            )}
        </>
    );
}

/**
 * エラーの本体（アイコン→見出し→説明→（未認証のみ）ログイン導線）。
 * 構成はマッチング結果の空状態（Pages/Matching/Show.tsx）に倣う。
 *
 * `guest` は「GuestLayout のカード内に描画されるか」を表す。カードの内寸は約 328px
 * （max-w-[400px] − px-9）しかなく、コンテンツ領域いっぱいを使う認証済みの表示と同じ寸法だと
 * 見出しが不格好に折り返す。そのため文字サイズを 1 段階下げる。
 * 逆に認証済み側は画面全体がこの 1 枚だけになるため、空状態より 1 段階大きくしている。
 *
 * ステータスコード（404 等）は表示しない。見出しの文言で状況と取るべき行動は区別でき、
 * 数字は業務利用者に追加情報を与えないため（500 の調査に要るのは発生時刻とログであって
 * ステータス番号ではない）。
 */
function ErrorBody({
    content,
    icon: Icon,
    guest,
    showLoginLink,
}: {
    content: ErrorContent;
    icon: ComponentType<{ className?: string }>;
    guest: boolean;
    showLoginLink: boolean;
}) {
    return (
        <div className="flex flex-col items-center justify-center text-center">
            <Icon
                className={cn(
                    'text-muted-foreground',
                    guest ? 'mb-2 h-8 w-8' : 'mb-3 h-10 w-10',
                )}
            />
            {/* text-balance：狭いカード内で最終行に 1 文字だけ残るような折り返しを避ける */}
            <p
                className={cn(
                    'text-balance font-semibold text-foreground',
                    guest ? 'text-sm' : 'text-base',
                )}
            >
                {content.title}
            </p>
            {/* whitespace-pre-line：文言側の \n を改行として反映する（連続空白は畳む） */}
            <p
                className={cn(
                    'mt-2 max-w-md whitespace-pre-line text-muted-foreground',
                    guest ? 'text-xs' : 'text-sm',
                )}
            >
                {content.description}
            </p>
            {/*
             * 復帰導線は未認証のときだけ置く。認証済みではサイドバー（AppSidebar）に
             * ダッシュボード・各一覧へのリンクが常時出ており、同じ導線の二重化になるため
             *（「対象が見つからない」ときに実際に行きたいのは該当の一覧であり、それもサイドバーにある）。
             * 体裁はダッシュボードの遷移リンク（Dashboard.tsx）に合わせ、案内文の延長として読ませる。
             */}
            {showLoginLink && (
                <Link
                    href={route('login')}
                    className="mt-4 text-xs font-medium text-primary hover:underline"
                >
                    ログイン画面へ
                </Link>
            )}
        </div>
    );
}

/**
 * 未認証では AuthenticatedLayout を使えない。AppSidebar が auth.user.role / auth.user.name に
 * 無条件アクセスしており、user が null だと実行時エラーになるため。
 *
 * 器（破線ボックス）は認証済みのときだけ付ける。GuestLayout は既にカード枠を持っており、
 * その中に破線ボックスを入れると枠が二重になるため。
 */
function renderWithLayout(body: ReactNode, user: User | null): ReactNode {
    if (!user) {
        return <GuestLayout>{body}</GuestLayout>;
    }

    // header prop は使わない。他の全画面が prop なしで、コンテンツ内に画面名の <h1> を置く構成であり、
    // エラーページには対応する画面名が無い。破線ボックス内の見出しが説明を担うため、
    // 上部に「エラー」と出すと見出しが二重になる。
    return (
        <AuthenticatedLayout>
            {/*
             * 破線ボックスはマッチング結果の空状態と同じ器（Pages/Matching/Show.tsx）。
             * ただしあちらはページ内の一区画なので固定の py-16 でよいのに対し、こちらはページ全体が
             * この 1 枚だけになる。高さをコンテンツ領域（100vh から AuthenticatedLayout の p-6 上下ぶんを引いた分）に
             * 合わせ、中身を上下中央に置く。そうしないと枠が上端に寄り、下に大きな余白だけが残る。
             */}
            <div className="flex min-h-[calc(100vh-3rem)] items-center justify-center rounded-md border border-dashed border-border">
                {body}
            </div>
        </AuthenticatedLayout>
    );
}
