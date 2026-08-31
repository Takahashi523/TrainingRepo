import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import FormFieldSettingTable from '@/Components/Master/FormFieldSettingTable';
import UserFormDialog from '@/Components/Master/UserFormDialog';
import UserTable from '@/Components/Master/UserTable';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { MasterPageProps, MasterUser } from '@/types/master';
import { Head, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';

type Props = PageProps<MasterPageProps>;

type TabKey = 'users' | 'form';

export default function Index({
    users,
    form_settings,
    allowed_email_domains,
    auth,
}: Props) {
    const [activeTab, setActiveTab] = useState<TabKey>('users');

    // ユーザー追加・編集モーダル（editingUser=null で新規追加）
    const [userDialogOpen, setUserDialogOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<MasterUser | null>(null);

    // 削除確認
    const [deleteTarget, setDeleteTarget] = useState<MasterUser | null>(null);
    const [deleteProcessing, setDeleteProcessing] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const openCreate = () => {
        setEditingUser(null);
        setUserDialogOpen(true);
    };

    const openEdit = (user: MasterUser) => {
        setEditingUser(user);
        setUserDialogOpen(true);
    };

    const openDelete = (user: MasterUser) => {
        setDeleteError(null);
        setDeleteTarget(user);
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        setDeleteProcessing(true);
        setDeleteError(null);
        router.delete(route('master.users.destroy', deleteTarget.id), {
            preserveScroll: true,
            onError: (errors) => {
                // 自己削除・最後の管理者・担当中は 422 でメッセージが返る
                setDeleteError(errors.delete ?? '削除できませんでした。');
            },
            onSuccess: () => setDeleteTarget(null),
            onFinish: () => setDeleteProcessing(false),
        });
    };

    // 地色（bg-muted/30）は <main> に載せる。本文側に置くと内容が短いときに
    // 画面下部が <main> の白背景のまま残るため（issue #82）。
    return (
        <AuthenticatedLayout mainClassName="bg-muted/30">
            <Head title="マスタ管理" />

            {/* WF_12 準拠：フルブリードのヘッダー＋アンダーラインタブ＋本文（進捗管理・完了済みと同構造）。
                スクロール境界は AuthenticatedLayout の <main> 1か所に一本化し、ヘッダー＋タブは同じ
                スクロール箱の中で sticky 固定する。固定領域をスクロール箱の外に置くとスクロールバー幅
                （約15px）を本文だけが内側で負担し、左右端が一致しない（issue #82）。
                -m-6 は <main> 内側 div の p-6 を打ち消してフルブリードにするためだけに残す。 */}
            <div className="-m-6">
                <div className="sticky top-0 z-10 bg-white">
                    {/* ページヘッダー */}
                    <div className="border-b border-border px-10 py-4">
                        <h1 className="text-lg font-bold text-foreground">マスタ管理</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            ユーザーアカウントと登録フォームの必須/任意設定を管理します
                        </p>
                    </div>

                    {/* アンダーライン型タブ（WF_10＝進捗管理準拠）。
                        左右パディングは画面共通のガター（px-10）に合わせる（issue #82）。 */}
                    <div className="flex items-end border-b-2 border-border bg-white px-10">
                        <TabItem
                            label="ユーザー管理"
                            count={activeTab === 'users' ? users.length : undefined}
                            isActive={activeTab === 'users'}
                            onClick={() => setActiveTab('users')}
                        />
                        <TabItem
                            label="フォーム設定"
                            isActive={activeTab === 'form'}
                            onClick={() => setActiveTab('form')}
                        />
                    </div>
                </div>

                {/* コンテンツエリア。左右ガターは固定領域（px-10）と揃える。 */}
                <div className="px-10 py-6">
                    {activeTab === 'users' && (
                        <>
                            <div className="mb-3 flex justify-end">
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="h-3.5 w-3.5" />
                                    新規ユーザー登録
                                </Button>
                            </div>
                            <UserTable
                                users={users}
                                currentUserId={auth.user.id}
                                onEdit={openEdit}
                                onDelete={openDelete}
                            />
                        </>
                    )}

                    {activeTab === 'form' && (
                        <div className="space-y-4">
                            <p className="text-xs text-muted-foreground">
                                変更は即時反映されます。全ユーザーの登録フォームに適用されます。
                            </p>
                            {/* 人材/案件を横並びにして縦スクロールを抑える（狭い画面では縦積み） */}
                            <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                                <section className="space-y-2">
                                    <h2 className="text-sm font-bold text-foreground">
                                        人材登録フォーム
                                    </h2>
                                    <FormFieldSettingTable
                                        formType="engineer"
                                        settings={form_settings.engineer}
                                    />
                                </section>
                                <section className="space-y-2">
                                    <h2 className="text-sm font-bold text-foreground">
                                        案件登録フォーム
                                    </h2>
                                    <FormFieldSettingTable
                                        formType="project"
                                        settings={form_settings.project}
                                    />
                                </section>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <UserFormDialog
                open={userDialogOpen}
                user={editingUser}
                currentUserId={auth.user.id}
                allowedEmailDomains={allowed_email_domains}
                onClose={() => setUserDialogOpen(false)}
            />

            <ConfirmDialog
                open={deleteTarget !== null}
                title="ユーザーを削除しますか？"
                description={
                    <>
                        <span className="font-bold">{deleteTarget?.name}</span> を削除します。この操作は取り消せません。
                        {deleteError && (
                            <span className="mt-2 block text-destructive">
                                {deleteError}
                            </span>
                        )}
                    </>
                }
                confirmLabel="削除する"
                processing={deleteProcessing}
                processingLabel="削除中..."
                onConfirm={confirmDelete}
                onCancel={() => setDeleteTarget(null)}
            />
        </AuthenticatedLayout>
    );
}

/**
 * アンダーライン型タブ見出し（進捗管理 PipelineTabHeader の TabItem と同じ見た目）。
 * こちらはページ内 state 切替のため router.get ではなく onClick で activeTab を切り替える。
 */
function TabItem({
    label,
    count,
    isActive,
    onClick,
}: {
    label: string;
    count?: number;
    isActive: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                '-mb-0.5 border-b-[3px] px-4 py-2.5 text-[13px] font-semibold whitespace-nowrap transition-colors',
                isActive
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
            {count != null && (
                <span className="ml-1.5 inline-block rounded-full bg-primary px-1.5 text-[10px] font-bold text-white">
                    {count}
                </span>
            )}
        </button>
    );
}
