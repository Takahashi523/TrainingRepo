import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import EmptyValue from '@/Components/Common/EmptyValue';
import FieldRow from '@/Components/Common/FieldRow';
import SkillTagDetail from '@/Components/Common/SkillTagDetail';
import StatusBadge from '@/Components/Common/StatusBadge';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import { Button } from '@/Components/ui/button';
import { useToast } from '@/hooks/use-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerShowPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Clock, Pencil, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Props = PageProps<EngineerShowPageProps>;

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

    // 経験工程を共通 ProcessCheckboxGroup で表示する（人材は has_experience フラグ）。
    const { phaseList, phaseValues } = buildProcessPhaseProps(engineer.phases, 'has_experience');

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
                        // 提案不可の人材はマッチング対象外（サーバー側 MatchingController でも弾く）。
                        disabled={engineer.status === 'not_proposable'}
                        title={
                            engineer.status === 'not_proposable'
                                ? '提案不可の人材はマッチングを実行できません'
                                : undefined
                        }
                    >
                        <ArrowLeftRight className="mr-1.5 h-3.5 w-3.5" />
                        マッチング
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
                            <StatusBadge status={engineer.status} />
                            {/* サマリーは「値がある項目だけを出す」（下部の項目表に全項目が必ず出るため）。
                                最寄駅・年齢と同じ扱いにそろえ、未定のときはピルごと出さない。
                                アイコンは視覚的なスキャン補助で、項目名は sr-only で支援技術に渡す。 */}
                            {engineer.available_from && (
                                <span className="rounded-full border border-dashed border-border bg-muted/50 px-3 py-0.5 text-xs">
                                    <span className="sr-only">稼働可能時期：</span>
                                    <Clock aria-hidden="true" className="mr-1 inline h-3 w-3 text-muted-foreground" />
                                    {engineer.available_label}
                                </span>
                            )}
                        </div>
                        <div className="mt-2.5 flex flex-wrap items-center gap-3 text-xs">
                            {/* 最寄駅はラベル語を出さず sr-only で項目名を渡す（表示規約の型2。
                                一覧カード・マッチングサマリーと同じ表現）。担当／サブは同型の人名が
                                並ぶため型3 のラベルを維持する。 */}
                            {(engineer.nearest_station || engineer.nearest_line) && (
                                <span>
                                    <span className="sr-only">最寄駅：</span>
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
                        ※ AIがアピールポイントをもとに自動生成した要約です。内容は参考情報としてご確認ください。
                    </p>
                </div>

                {/* 基本情報 */}
                <SectionCard title="基本情報">
                    <FieldRow density="detail" label="氏名 / カナ">
                        {engineer.name}{'　／　'}{engineer.name_kana}
                    </FieldRow>
                    <FieldRow density="detail" label="年齢（生年月日）">
                        {engineer.age != null ? `${engineer.age}歳` : <EmptyValue field="age" />}
                        {engineer.birth_date && (
                            <span className="ml-2 text-xs">
                                （{new Date(engineer.birth_date).toLocaleDateString('ja-JP', {
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                })}生まれ）
                            </span>
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="最寄駅 / 路線">
                        {engineer.nearest_station || <EmptyValue field="nearestStation" />}{'　／　'}
                        {engineer.nearest_line || <EmptyValue field="nearestLine" />}
                    </FieldRow>
                    <FieldRow density="detail" label="稼働可能時期">
                        {/* サーバの available_label は null のとき「未定」という文字列を返すが、
                            欠損は控えめな色で見せるため、値の有無で描き分ける（色をトークン側に持たせる）。 */}
                        {engineer.available_from ? (
                            engineer.available_label
                        ) : (
                            <EmptyValue field="availableFrom" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* スキル情報 */}
                <SectionCard title="スキル情報">
                    <FieldRow density="detail" label="経験スキル">
                        {engineer.skills.length > 0 ? (
                            <div className="flex flex-wrap gap-1.5">
                                {engineer.skills.map((skill, i) => (
                                    <SkillTagDetail key={`${skill.label ?? ''}-${i}`} label={skill.label ?? ''} detail={skill.detail} />
                                ))}
                            </div>
                        ) : (
                            <EmptyValue field="skills" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="経験工程">
                        <ProcessCheckboxGroup
                            phases={phaseList}
                            values={phaseValues}
                            readOnly
                            className="flex-nowrap gap-x-4"
                        />
                    </FieldRow>
                    <FieldRow density="detail" label="顧客折衝経験">
                        {engineer.has_negotiation_exp === true
                            ? '有'
                            : engineer.has_negotiation_exp === false
                              ? '無'
                              : <EmptyValue field="negotiationExp" />}
                    </FieldRow>
                </SectionCard>

                {/* 経歴・PR */}
                <SectionCard title="経歴・PR">
                    <FieldRow density="detail" label="アピールポイント">
                        {engineer.appeal_note ? (
                            <p className="whitespace-pre-wrap leading-relaxed">{engineer.appeal_note}</p>
                        ) : (
                            <EmptyValue field="appealNote" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* 希望条件 */}
                <SectionCard title="希望条件">
                    <FieldRow density="detail" label="希望単価（月額）">
                        {engineer.desired_rate != null ? `${engineer.desired_rate}万円` : <EmptyValue field="desiredRate" />}
                    </FieldRow>
                    <FieldRow density="detail" label="勤務形態">
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
                            <EmptyValue field="workStyle" />
                        )}
                    </FieldRow>
                    <FieldRow density="detail" label="特記事項">
                        {engineer.remarks ? (
                            <p className="whitespace-pre-wrap leading-relaxed">
                                {engineer.remarks}
                            </p>
                        ) : (
                            <EmptyValue field="remarks" />
                        )}
                    </FieldRow>
                </SectionCard>

                {/* 管理情報 */}
                <SectionCard title="管理情報">
                    <FieldRow density="detail" label="ステータス">
                        <StatusBadge status={engineer.status} />
                    </FieldRow>
                    <FieldRow density="detail" label="担当営業">
                        <span>担当：{engineer.users.main.name}</span>
                        {engineer.users.sub && (
                            <span className="ml-3">
                                ／　サブ：{engineer.users.sub.name}
                            </span>
                        )}
                    </FieldRow>
                </SectionCard>

            </div>

            {/* Delete confirmation dialog */}
            {showDeleteConfirm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
                    <div className="w-full max-w-sm rounded-lg border border-border bg-white p-6 shadow-xl">
                        <h2 className="mb-2 text-base font-bold text-foreground">人材情報を削除しますか？</h2>
                        <p className="mb-5 text-sm text-muted-foreground">
                            <strong>{engineer.name}</strong> の情報を物理削除します。この操作は取り消せません。
                            {engineer.pipelines_count > 0 && (
                                <span className="mt-2 block text-destructive">
                                    この人材に紐づくパイプライン {engineer.pipelines_count} 件も同時に削除されます。
                                </span>
                            )}
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
