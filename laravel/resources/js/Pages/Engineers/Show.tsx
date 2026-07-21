import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import SkillTagDetail from '@/Components/Common/SkillTagDetail';
import StatusBadge from '@/Components/Common/StatusBadge';
import ProcessCheckboxGroup from '@/Components/Engineers/ProcessCheckboxGroup';
import { Button } from '@/Components/ui/button';
import { useToast } from '@/hooks/use-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerShowPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Clock, Pencil, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Props = PageProps<EngineerShowPageProps>;

const ENGINEER_STATUS_LABELS: Record<string, string> = {
    proposable:     '提案可',
    interviewing:   '面談中',
    not_proposable: '提案不可',
};

function SectionCard({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="mb-4 overflow-visible rounded-md border border-border">
            <div className="rounded-t-md border-b border-border bg-muted/50 px-4 py-2.5 text-xs font-bold text-foreground">
                {title}
            </div>
            {children}
        </div>
    );
}

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start border-b border-border/50 px-4 py-2.5 last:border-b-0">
            <div className="w-44 shrink-0 pr-4 pt-0.5 text-xs font-semibold text-muted-foreground">
                {label}
            </div>
            <div className="min-w-0 flex-1 text-sm text-foreground">{children}</div>
        </div>
    );
}


export default function Show({ engineer }: Props) {
    const { auth } = usePage<Props>().props;
    const isAdmin = auth.user.role === 'admin';

    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    // マッチング実行は遷移先描画前にサーバーで Python AI を同期呼び出しするため数秒待つ。
    // その間、遷移元のこの画面に AI 計算中オーバーレイを被せる（onStart で表示・onFinish で解除）。
    const [isMatching, setIsMatching] = useState(false);
    const { toast } = useToast();

    // マッチングは読み取り専用（DB保存なし）のため、途中キャンセルは安全（副作用が残らない）。
    // Inertia visit の cancel トークンを保持し、オーバーレイのキャンセルボタンから中断する。
    const matchingCancel = useRef<(() => void) | null>(null);

    const handleDelete = () => {
        router.delete(`/engineers/${engineer.id}`, {
            onStart:   () => setIsDeleting(true),
            onFinish:  () => setIsDeleting(false),
            onSuccess: () => setShowDeleteConfirm(false),
        });
    };

    const aiGeneratedAt = engineer.ai_summary_generated_at
        ? new Date(engineer.ai_summary_generated_at).toLocaleDateString('ja-JP', {
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
          })
        : null;

    return (
        <AuthenticatedLayout>
            <Head title="人材詳細" />
            {/* マッチング実行の遷移中（Python AI 同期計算）に全画面で計算中を表示する。
                共通部品のデフォルトは汎用文言のため、ここではマッチング用途の具体文言を渡す。
                マッチングは読み取り専用でキャンセルが安全なため onCancel を渡す（visit を中断）。 */}
            <AiLoadingOverlay
                show={isMatching}
                message="AIがマッチングを計算しています…"
                onCancel={() => matchingCancel.current?.()}
            />

            {/* Sticky page header */}
            <div className="sticky top-0 z-10 -mx-6 -mt-6 mb-6 flex items-center justify-between border-b border-border bg-white px-10 py-4">
                <div>
                    <h1 className="text-lg font-bold text-foreground">人材詳細</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">人材の登録情報を確認します</p>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        onClick={() =>
                            router.get(
                                // ルートは一覧カードと統一（`/engineers/{id}/matching`＝engineers.matching）。
                                // 旧 `/matching/{id}` は未定義ルートで 404 になっていたため廃止（9-6）。
                                `/engineers/${engineer.id}/matching`,
                                {},
                                {
                                    onStart: () => setIsMatching(true),
                                    // Inertia が渡す cancel トークンを保持し、オーバーレイのキャンセルボタンで叩けるようにする。
                                    onCancelToken: (token) => {
                                        matchingCancel.current = token.cancel;
                                    },
                                    // サーバー到達エラー（通信断・リクエスト中断）は成功レスポンスの
                                    // flash.error では拾えないため、ここでトースト表示し Silent Rejection を防ぐ。
                                    // （エンジン通信失敗など到達済みエラーはサーバーが flash.error で通知する）
                                    onError: () =>
                                        toast({
                                            description:
                                                'マッチングの実行に失敗しました。通信環境をご確認のうえ、再度お試しください。',
                                            variant: 'destructive',
                                        }),
                                    // onFinish は成功・失敗・キャンセルすべてで発火するためオーバーレイは必ず解除される。
                                    onFinish: () => {
                                        setIsMatching(false);
                                        matchingCancel.current = null;
                                    },
                                },
                            )
                        }
                    >
                        <ArrowLeftRight className="mr-1.5 h-3.5 w-3.5" />
                        マッチング実行
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => router.get(`/engineers/${engineer.id}/edit`)}
                    >
                        <Pencil className="mr-1.5 h-3.5 w-3.5" />
                        編集する
                    </Button>
                    {isAdmin && (
                        <div className="flex flex-col items-end gap-0.5">
                            <Button
                                variant="outline"
                                className="border-destructive text-destructive hover:bg-destructive/5"
                                onClick={() => setShowDeleteConfirm(true)}
                            >
                                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                                削除
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            <div className="max-w-3xl">

                {/* Profile summary */}
                <div className="mb-6 flex items-start gap-5 border-b border-border pb-6">
                    <div className="min-w-0 flex-1">
                        <p className="text-2xl font-bold text-foreground">{engineer.name}</p>
                        <p className="mt-0.5 text-xs">
                            {engineer.name_kana}
                            {engineer.age != null && (
                                <span className="ml-2">　{engineer.age}歳</span>
                            )}
                        </p>
                        <div className="mt-2.5 flex flex-wrap items-center gap-2">
                            <StatusBadge
                                status={engineer.status}
                                label={ENGINEER_STATUS_LABELS[engineer.status] ?? engineer.status}
                            />
                            <span className="rounded-full border border-dashed border-border bg-muted/50 px-3 py-0.5 text-xs">
                                <Clock className="mr-1 inline h-3 w-3" />{engineer.available_label}
                            </span>
                        </div>
                        <div className="mt-2.5 flex flex-wrap items-center gap-3 text-xs">
                            {(engineer.nearest_station || engineer.nearest_line) && (
                                <span>
                                    <span className="mr-1 font-semibold text-foreground/60">最寄駅</span>
                                    {[engineer.nearest_station, engineer.nearest_line]
                                        .filter(Boolean)
                                        .join('（') + (engineer.nearest_line ? '）' : '')}
                                </span>
                            )}
                            {(engineer.nearest_station || engineer.nearest_line) && (
                                <span className="h-3.5 w-px bg-border" />
                            )}
                            <span>
                                <span className="mr-1 font-semibold text-foreground/60">担当</span>
                                {engineer.users.main.name}
                                {engineer.users.sub && (
                                    <>
                                        <span className="mx-1 text-border">／</span>
                                        <span className="mr-1 font-semibold text-foreground/60">サブ</span>
                                        {engineer.users.sub.name}
                                    </>
                                )}
                            </span>
                        </div>
                    </div>
                </div>

                {/* AI summary */}
                <div className="mb-4 rounded-md border border-border bg-muted/30 px-5 py-4">
                    <div className="mb-2.5 flex items-center gap-2">
                        <span className="rounded bg-purple-600 px-1.5 py-0.5 text-[9px] font-bold text-white">
                            AI
                        </span>
                        <span className="text-xs font-bold text-foreground">職務要約</span>
                        {aiGeneratedAt && (
                            <span className="ml-auto text-xs text-muted-foreground">
                                最終生成：{aiGeneratedAt}
                            </span>
                        )}
                    </div>
                    {engineer.ai_summary ? (
                        <p className="text-sm leading-relaxed text-foreground">
                            {engineer.ai_summary}
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">AI要約は未生成です</p>
                    )}
                    <p className="mt-2 text-[10px] leading-relaxed text-muted-foreground">
                        ※ AIがプロフィール情報（スキル・工程経験・アピールポイント等）をもとに自動生成した要約です。内容は参考情報としてご確認ください。
                    </p>
                </div>

                {/* 基本情報 */}
                <SectionCard title="基本情報">
                    <DetailRow label="氏名 / カナ">
                        {engineer.name}　／　{engineer.name_kana}
                    </DetailRow>
                    <DetailRow label="年齢（生年月日）">
                        {engineer.age != null ? `${engineer.age}歳` : '—'}
                        {engineer.birth_date && (
                            <span className="ml-2 text-xs">
                                （{new Date(engineer.birth_date).toLocaleDateString('ja-JP', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}生まれ）
                            </span>
                        )}
                    </DetailRow>
                    <DetailRow label="最寄駅 / 路線">
                        {engineer.nearest_station || '—'}
                        {engineer.nearest_line && (
                            <span className="ml-1">　{engineer.nearest_line}</span>
                        )}
                    </DetailRow>
                    <DetailRow label="稼働可能時期">
                        {engineer.available_label}
                    </DetailRow>
                </SectionCard>

                {/* スキル情報 */}
                <SectionCard title="スキル情報">
                    <DetailRow label="経験スキル">
                        {engineer.skills.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {engineer.skills.map((skill, i) => (
                                    <SkillTagDetail key={i} label={skill.label ?? ''} detail={skill.detail} />
                                ))}
                            </div>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                    <DetailRow label="経験工程">
                        <ProcessCheckboxGroup
                            phases={engineer.phases.map(({ key, name }) => ({ key, name }))}
                            values={Object.fromEntries(engineer.phases.map((p) => [p.key, p.has_experience]))}
                            readOnly
                            className="flex-nowrap gap-x-4"
                        />
                    </DetailRow>
                    <DetailRow label="顧客折衝経験">
                        {engineer.has_negotiation_exp === true
                            ? '有'
                            : engineer.has_negotiation_exp === false
                              ? '無'
                              : '—'}
                    </DetailRow>
                </SectionCard>

                {/* 経歴・PR */}
                <SectionCard title="経歴・PR">
                    <DetailRow label="アピールポイント">
                        {engineer.appeal_note ? (
                            <p className="whitespace-pre-wrap leading-relaxed">{engineer.appeal_note}</p>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                </SectionCard>

                {/* 希望条件 */}
                <SectionCard title="希望条件">
                    <DetailRow label="希望単価（月額）">
                        {engineer.desired_rate != null ? `${engineer.desired_rate}万円` : '—'}
                    </DetailRow>
                    <DetailRow label="勤務形態">
                        {engineer.work_styles.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {engineer.work_styles.map((wt) => (
                                    <span
                                        key={wt.key}
                                        className="rounded border border-dashed border-border px-2 py-0.5 text-xs"
                                    >
                                        {wt.name}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                    <DetailRow label="特記事項">
                        {engineer.remarks ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {engineer.remarks}
                            </p>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </DetailRow>
                </SectionCard>

                {/* 管理情報 */}
                <SectionCard title="管理情報">
                    <DetailRow label="ステータス">
                        <StatusBadge
                            status={engineer.status}
                            label={ENGINEER_STATUS_LABELS[engineer.status] ?? engineer.status}
                        />
                    </DetailRow>
                    <DetailRow label="担当営業">
                        <span>担当：{engineer.users.main.name}</span>
                        {engineer.users.sub && (
                            <span className="ml-3">
                                ／　サブ：{engineer.users.sub.name}
                            </span>
                        )}
                    </DetailRow>
                </SectionCard>

            </div>

            {/* Delete confirmation dialog */}
            {showDeleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-full max-w-sm rounded-lg border border-border bg-white p-6 shadow-xl">
                        <h2 className="mb-2 text-base font-bold text-foreground">人材情報を削除しますか？</h2>
                        <p className="mb-5 text-sm text-muted-foreground">
                            <strong>{engineer.name}</strong> の情報を物理削除します。この操作は取り消せません。
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setShowDeleteConfirm(false)}>
                                キャンセル
                            </Button>
                            <Button
                                variant="destructive"
                                onClick={handleDelete}
                                disabled={isDeleting}
                            >
                                {isDeleting ? '削除中...' : '削除する'}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
