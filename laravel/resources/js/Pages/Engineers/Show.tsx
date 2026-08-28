import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import EmptyValue from '@/Components/Common/EmptyValue';
import FieldRow from '@/Components/Common/FieldRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import Rate from '@/Components/Common/Rate';
import SkillTagDetail from '@/Components/Common/SkillTagDetail';
import StatusBadge from '@/Components/Common/StatusBadge';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import { Button } from '@/Components/ui/button';
import { useToast } from '@/hooks/use-toast';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { EngineerShowPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, ArrowLeftRight, Clock, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';

type Props = PageProps<EngineerShowPageProps>;

function SectionCard({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="mb-4 overflow-visible rounded-md border border-border bg-white">
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

    // AI要約の再生成（issue #61）は保存を伴う書き込み処理のため、AiLoadingOverlay に onCancel は渡さない
    // （クライアント側でキャンセルしてもサーバー側の保存は止まらず、UIと実データが不整合になるため。
    //  コンポーネント側のドキュメント参照）。
    const [isRegeneratingAiSummary, setIsRegeneratingAiSummary] = useState(false);

    // 経験工程を共通 ProcessCheckboxGroup で表示する（人材は has_experience フラグ）。
    const { phaseList, phaseValues } = buildProcessPhaseProps(engineer.phases, 'has_experience');

    const handleDelete = () => {
        router.delete(`/engineers/${engineer.id}`, {
            onStart:   () => setIsDeleting(true),
            onFinish:  () => setIsDeleting(false),
            onSuccess: () => setShowDeleteConfirm(false),
        });
    };

    const handleRegenerateAiSummary = () => {
        router.post(
            `/engineers/${engineer.id}/ai-summary/regenerate`,
            {},
            {
                preserveScroll: true,
                onStart: () => setIsRegeneratingAiSummary(true),
                onFinish: () => setIsRegeneratingAiSummary(false),
                onError: () =>
                    toast({
                        description:
                            'AI要約の再生成に失敗しました。通信環境をご確認のうえ、再度お試しください。',
                        variant: 'destructive',
                    }),
            },
        );
    };

    const aiGeneratedAt = engineer.ai_summary_generated_at
        ? new Date(engineer.ai_summary_generated_at).toLocaleDateString('ja-JP', {
              year: 'numeric',
              month: '2-digit',
              day: '2-digit',
          })
        : null;

    return (
        <AuthenticatedLayout mainClassName="bg-muted/30">
            <Head title="人材詳細" />
            {/* マッチング実行の遷移中（Python AI 同期計算）に全画面で計算中を表示する。
                共通部品のデフォルトは汎用文言のため、ここではマッチング用途の具体文言を渡す。
                マッチングは読み取り専用でキャンセルが安全なため onCancel を渡す（visit を中断）。 */}
            <AiLoadingOverlay
                show={isMatching}
                message="AIがマッチングを計算しています…"
                onCancel={() => matchingCancel.current?.()}
            />

            {/* AI要約の再生成中オーバーレイ（issue #61）。保存を伴うため onCancel は渡さない。 */}
            <AiLoadingOverlay
                show={isRegeneratingAiSummary}
                message="AIが職務要約を生成しています…"
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
                        {/* ステータスは氏名の右に置く（マッチングサマリーと同じ構成）。 */}
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-2xl font-bold text-foreground">{engineer.name}</p>
                            <StatusBadge status={engineer.status} className="shrink-0" />
                        </div>
                        <p className="mt-0.5 text-xs">{engineer.name_kana}</p>
                        {/* 属性メタ（型2）と担当／サブ（型3）を1つの流れに並べる。
                            担当・サブは同型の人名が並ぶため型3 のラベルを維持する。
                            サマリーなので値がある項目だけを出す（全項目は下部の項目表に出る）。 */}
                        <MetaRow className="mt-2.5 text-xs">
                            {engineer.age != null && (
                                <MetaItem field="age">{engineer.age}歳</MetaItem>
                            )}
                            {(engineer.nearest_station || engineer.nearest_line) && (
                                <MetaItem field="nearestStation">
                                    {[engineer.nearest_station, engineer.nearest_line]
                                        .filter(Boolean)
                                        .join('（') + (engineer.nearest_line ? '）' : '')}
                                </MetaItem>
                            )}
                            {engineer.available_from && (
                                <MetaItem field="availableFrom" icon={Clock}>
                                    {engineer.available_label}
                                </MetaItem>
                            )}
                            {/* 担当・サブは同型の人名が並ぶため型3 のラベルを維持する。 */}
                            <span>
                                担当：{engineer.users.main.name}
                                {engineer.users.sub && (
                                    <>
                                        <span className="mx-1 text-border">／</span>
                                        サブ：{engineer.users.sub.name}
                                    </>
                                )}
                            </span>
                        </MetaRow>

                    </div>
                </div>

                {/* AI summary */}
                {/* ページの地色が bg-muted/30 のため、同じ塗りだと枠が沈む。白にして浮かせる。 */}
                <div className="mb-4 rounded-md border border-border bg-white px-5 py-4">
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

                    {/* issue #61：恒久的な失敗表示。submit直後の一過性トーストだけでなく、後から詳細画面を
                        開いても失敗状態が分かるようにする。 */}
                    {engineer.ai_summary_status === 'failed' && (
                        <div className="mb-2.5 flex items-center gap-1.5 rounded border border-destructive/20 bg-destructive/10 px-2.5 py-1.5 text-xs text-destructive">
                            <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                            AI要約の生成に失敗しました。「再生成」からもう一度お試しください。
                        </div>
                    )}

                    {/* stale（陳腐化）：failed のときは失敗バナーで案内済みのため、二重表示を避けて出し分ける。 */}
                    {engineer.ai_summary_status !== 'failed' && engineer.is_ai_summary_stale && (
                        <div className="mb-2.5 flex items-center gap-1.5 rounded border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800">
                            <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                            アピールポイントの変更後、要約が更新されていません。内容が古い可能性があります。
                        </div>
                    )}

                    {engineer.ai_summary ? (
                        <p className="text-sm leading-relaxed text-foreground">
                            {engineer.ai_summary}
                        </p>
                    ) : engineer.ai_summary_status === 'empty' ? (
                        <p className="text-sm text-muted-foreground">
                            AIが要約可能な内容を検出できませんでした
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">AI要約は未生成です</p>
                    )}

                    <div className="mt-2.5 flex items-end justify-between gap-3">
                        <p className="text-[10px] leading-relaxed text-muted-foreground">
                            ※ AIがアピールポイントをもとに自動生成した要約です。内容は参考情報としてご確認ください。
                        </p>
                        {/* issue #61 課題2：appeal_note の変更有無に依存しない明示的な再生成手段。
                            appeal_note が空の人材は生成対象がないため無効化する。 */}
                        <Button
                            variant="outline"
                            size="sm"
                            className="shrink-0"
                            disabled={!engineer.appeal_note || isRegeneratingAiSummary}
                            title={
                                !engineer.appeal_note
                                    ? 'アピールポイントが未入力のため再生成できません'
                                    : undefined
                            }
                            onClick={handleRegenerateAiSummary}
                        >
                            <RefreshCw className="mr-1.5 h-3 w-3" />
                            再生成
                        </Button>
                    </div>
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
                        {/* 案件詳細の単価（Projects/Show）と同じ Rate に載せ、単位「万円」の濃度・サイズを揃える。
                            希望単価は単一値なので range ではなく value モードを使う（範囲記号を付けない）。 */}
                        <Rate value={engineer.desired_rate} />
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

            {/* 削除確認は共通 ConfirmDialog（AlertDialog ベース）で行う。
                手組みモーダルでは得られない role="alertdialog"・フォーカストラップ・
                Esc での閉じる・フォーカス復帰・背景の不活性化を標準機能に委ねる。 */}
            <ConfirmDialog
                open={showDeleteConfirm}
                title="人材情報を削除しますか？"
                description={
                    <>
                        <strong>{engineer.name}</strong> の情報を物理削除します。この操作は取り消せません。
                        {engineer.pipelines_count > 0 && (
                            <span className="mt-2 block text-destructive">
                                この人材に紐づくパイプライン {engineer.pipelines_count} 件も同時に削除されます。
                            </span>
                        )}
                    </>
                }
                processing={isDeleting}
                processingLabel="削除中..."
                onConfirm={handleDelete}
                onCancel={() => setShowDeleteConfirm(false)}
            />
        </AuthenticatedLayout>
    );
}
