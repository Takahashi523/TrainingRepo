import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import EmptyValue from '@/Components/Common/EmptyValue';
import FieldRow from '@/Components/Common/FieldRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import Rate from '@/Components/Common/Rate';
import SkillTagDetail from '@/Components/Common/SkillTagDetail';
import StatusBadge from '@/Components/Common/StatusBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { emptyText } from '@/lib/emptyValue';
import { EngineerShowPageProps } from '@/types/engineer';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Clock, Pencil, Trash2 } from 'lucide-react';
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
                                    // 通信断（サーバーに到達できない失敗）は onError では拾えないため、
                                    // レイアウトの useConnectionErrorToast()（exception 購読）で通知する（#84）。
                                    // 到達済みのエンジン通信失敗はサーバーが flash.error で通知する。
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
                            {/* 氏名・カナ（各100文字）は1行省略し、省略時のみホバーで全文を出す。
                                flex-1（基準サイズ0）が必須：min-w-0 だけでは「折り返すか」の判定に使う
                                基準サイズが max-content（truncate により全文1行分）のままなので、
                                氏名が親幅を超えた時点で氏名が1行目を独占し、ステータスバッジが2行目へ落ちる。
                                flex は基準サイズで折り返しを先に決め、縮小はその後に行うため。
                                max-w-fit とセットで使う：flex-1 だけだと氏名が余白まで伸びて
                                ステータスバッジが右端まで離れるので、内容幅以上には伸ばさない
                                （MetaRow の data-shrinkable 項目と同じ「flex-1 max-w-fit」の組み方）。 */}
                            <TruncatedText
                                as="p"
                                text={engineer.name}
                                className="min-w-0 max-w-fit flex-1 text-2xl font-bold text-foreground"
                            />
                            <StatusBadge status={engineer.status} className="shrink-0" />
                        </div>
                        <TruncatedText as="p" text={engineer.name_kana} className="mt-0.5 text-xs" />
                        {/* 属性メタ（型2）と担当／サブ（型3）を1つの流れに並べる。
                            担当・サブは同型の人名が並ぶため型3 のラベルを維持する。
                            サマリーなので値がある項目だけを出す（全項目は下部の項目表に出る）。 */}
                        {/* サマリーのメタは1行に収める（nowrap）。はみ出し分は data-shrinkable を付けた
                            可変長項目（最寄駅・路線／担当・サブ）だけが引き受け、年齢・稼働可能時期は縮まない。 */}
                        <MetaRow nowrap className="mt-2.5 text-xs">
                            {engineer.age != null && (
                                <MetaItem field="age">{engineer.age}歳</MetaItem>
                            )}
                            {/* 駅名・路線名（各100文字）は個別に1行省略する。1本の文字列に連結すると
                                駅名が長いだけで路線が丸ごと消えるため、片方が長くても他方が残るようにする
                                （一覧カード・マッチング結果画面と同じ組み方）。
                                両方空ならサマリーには出さないが、**片方だけ空のときは結合せず項目ごとに
                                欠損トークンを描く**（UI表示規約 §3）。片側を単に省くと、路線だけの行が
                                駅名の抜けた「（山手線）」になり、欠けている側が画面から読み取れなくなる。 */}
                            {(engineer.nearest_station || engineer.nearest_line) && (
                                <MetaItem
                                    field="nearestStation"
                                    // 駅名が空のときは値の先頭が欠損トークン「最寄駅未設定」＝項目名を含むため
                                    // sr-only を出さない（「最寄駅：最寄駅未設定」の二重読みを防ぐ）。
                                    valueHasFieldName={!engineer.nearest_station}
                                    data-shrinkable
                                    // gap-0：駅名と「（路線）」は1つの値の続きなので、MetaItem 既定の
                                    // 隙間（gap-1）を入れない（「東京駅 （山手線）」と割れて見えるため）。
                                    // 担当／サブのラベルと氏名と同じ扱い。
                                    className="gap-0"
                                >
                                    <TruncatedText
                                        text={
                                            engineer.nearest_station ||
                                            emptyText('nearestStation', true)
                                        }
                                        className="min-w-0 max-w-fit flex-1"
                                    />
                                    <TruncatedText
                                        text={
                                            engineer.nearest_line
                                                ? `（${engineer.nearest_line}）`
                                                : `（${emptyText('nearestLine', true)}）`
                                        }
                                        className="min-w-0 max-w-fit flex-1"
                                    />
                                </MetaItem>
                            )}
                            {engineer.available_from && (
                                <MetaItem field="availableFrom" icon={Clock}>
                                    {engineer.available_label}
                                </MetaItem>
                            )}
                            {/* 担当・サブは同型の人名が並ぶため型3 のラベルを維持する。
                                氏名（最大255文字）だけを1行省略し、ラベル語と区切り「／」は shrink-0 で常時表示する
                                （担当が長いだけで「サブ：」ごと消えるのを防ぐ）。
                                氏名2つは flex-1 max-w-fit で「取り分は等分・使わない分は相手に返す」にする。
                                既定の縮小は基準サイズに比例するため、担当だけが長いとサブが1文字まで潰れる。 */}
                            {/* ラベル語を可視で持つ型3 の項目だが、MetaItem を通すことで
                                data-shrinkable が型で守られる（生の span に直接書くと綴り間違いが
                                無言で「縮まない項目」に化け、行あふれとしてしか現れない）。
                                sr-only は valueHasFieldName で抑止（可視の「担当：」と二重に読ませない）。
                                gap-0：ラベルと氏名の間に MetaItem 既定の隙間を入れない。 */}
                            <MetaItem
                                field="mainUser"
                                valueHasFieldName
                                data-shrinkable
                                className="gap-0"
                            >
                                <span className="shrink-0">担当：</span>
                                <TruncatedText
                                    text={engineer.users.main.name}
                                    className="min-w-0 max-w-fit flex-1"
                                />
                                {engineer.users.sub && (
                                    <>
                                        <span className="mx-1 shrink-0 text-border">／</span>
                                        <span className="shrink-0">サブ：</span>
                                        <TruncatedText
                                            text={engineer.users.sub.name}
                                            className="min-w-0 max-w-fit flex-1"
                                        />
                                    </>
                                )}
                            </MetaItem>
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
