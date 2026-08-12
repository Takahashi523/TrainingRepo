import ConfirmDialog from '@/Components/Common/ConfirmDialog';
import DateInput from '@/Components/Common/DateInput';
import RankBadge, { RANK_BAR_FALLBACK_STYLE, RANK_BAR_STYLES } from '@/Components/Common/RankBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import PipelineStatusBadge from '@/Components/Pipelines/PipelineStatusBadge';
import StatusSelect from '@/Components/Pipelines/StatusSelect';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/Components/ui/accordion';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { useClientValidity } from '@/hooks/use-client-validity';
import { PageProps } from '@/types';
import { PipelineDetail, PipelineStatus, StatusOption } from '@/types/pipeline';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Trash2, X } from 'lucide-react';
import { useState } from 'react';

interface Props {
    pipeline: PipelineDetail;
    statusOptions: StatusOption[];
    onClose: () => void;
}

/** 最終更新日時（datetime）を日本語表記に整形 */
function formatDateTime(value: string): string {
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * パイプライン詳細ドロワー（右スライドのオーバーレイ）。
 * - スコアサマリ / AI 折りたたみ（推薦理由・不足条件）
 * - 管理情報フォーム（useForm：next_action_date / client_comment / ng_reason）
 * - ステータス変更 select（終了ステータス選択時は元に戻せない旨を共通 ConfirmDialog で警告）
 * - 保存は processing で二重送信防止、削除は共通 ConfirmDialog で確認（admin のみ描画）
 *
 * このコンポーネントは開くたびに Index 側で key={pipeline.id} により再マウントされる想定のため、
 * pipeline 切り替え時のフォーム再初期化は不要（初期値をそのまま useForm に渡す）。
 */
export default function PipelineDrawer({ pipeline, statusOptions, onClose }: Props) {
    const { auth } = usePage<PageProps>().props;
    const isAdmin = auth.user.role === 'admin';

    // 削除確認ダイアログの表示状態と削除処理中フラグ
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    // 終了ステータスへ変更して保存する際の確認ダイアログ表示状態
    const [showTerminalConfirm, setShowTerminalConfirm] = useState(false);

    const form = useForm({
        status: pipeline.status as PipelineStatus,
        next_action_date: pipeline.next_action_date ?? '',
        client_comment: pipeline.client_comment ?? '',
        ng_reason: pipeline.ng_reason ?? '',
    });
    const { data, setData, processing, errors } = form;

    // 数値・日付欄の silent rejection をクライアント側で検出（onBlur / 送信時ガード）。
    const { fieldProps, errors: clientErrors, validateAll } = useClientValidity();

    // 空文字はサーバへ null として送る（部分更新・nullable 項目）
    form.transform((d) => ({
        ...d,
        next_action_date: d.next_action_date === '' ? null : d.next_action_date,
        client_comment: d.client_comment === '' ? null : d.client_comment,
        ng_reason: d.ng_reason === '' ? null : d.ng_reason,
    }));

    // 保存本体：成功（2xx）時のみドロワーを閉じる。
    // バリデーションエラー（422）は onSuccess を発火させないため、エラー表示を保ったままドロワーは開いたまま残る。
    const doSave = () => {
        form.patch(route('pipelines.update', pipeline.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const handleSave = () => {
        // クライアント側の不正入力（日付の badInput 等）が残っていれば保存しない（確認ダイアログも出さない）。
        if (!validateAll()) return;

        // 終了ステータスへ変更しての保存は完了済みタブへ移動する不可逆操作のため、保存時に確認ダイアログを挟む。
        // 進行中 → 終了の遷移時のみ確認し、それ以外（進行中のまま・管理情報のみ変更）は即保存する。
        const changingToTerminal =
            !!statusOptions.find((o) => o.value === data.status)?.is_terminal &&
            !statusOptions.find((o) => o.value === pipeline.status)?.is_terminal;
        if (changingToTerminal) {
            setShowTerminalConfirm(true);
            return;
        }
        doSave();
    };

    // 削除確認は共通 ConfirmDialog で行う（人材詳細の削除と統一）。確定後に物理削除する。
    const confirmDelete = () => {
        router.delete(route('pipelines.destroy', pipeline.id), {
            preserveScroll: true,
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
            onSuccess: () => setShowDeleteConfirm(false),
        });
    };

    const score = pipeline.match_score;

    return (
        <>
            {/* オーバーレイ */}
            <div
                className="absolute inset-0 z-20 bg-black/25"
                onClick={onClose}
                aria-hidden="true"
            />

            {/* ドロワー本体 */}
            <div className="absolute inset-y-0 right-0 z-30 flex w-[480px] max-w-full flex-col border-l border-border bg-white shadow-[-4px_0_16px_rgba(0,0,0,0.12)]">
                {/* ヘッダ */}
                <div className="flex shrink-0 items-start justify-between border-b border-border p-4">
                    <div className="min-w-0">
                        {/* 氏名は最大255文字になり得るため TruncatedText で1行省略＋省略時のみ全文ツールチップを表示する */}
                        <TruncatedText
                            as="p"
                            text={pipeline.engineer.name}
                            className="text-[15px] font-bold text-foreground"
                        />
                        {/* 顧客名・案件名は個別に省略。短い時は内容幅でぴったり、溢れた時だけ各自 truncate（長い顧客名で案件名が丸ごと消えるのを防ぎ、全文はホバーで確認） */}
                        <p className="mt-0.5 mb-2 flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                            {/* 顧客名は nullable。無い場合は区切り「/」を出さず案件名のみ表示する（孤立セパレータ防止） */}
                            {pipeline.project.client_name && (
                                <>
                                    <TruncatedText text={pipeline.project.client_name} className="min-w-0" />
                                    <span className="shrink-0">/</span>
                                </>
                            )}
                            <TruncatedText text={pipeline.project.name} className="min-w-0" />
                        </p>

                        <div className="flex flex-wrap items-center gap-2">
                            <span className="shrink-0 text-[11px] font-semibold text-muted-foreground">
                                ステータス：
                            </span>
                            <PipelineStatusBadge label={pipeline.status_label} className="shrink-0" />
                            {/* ドロワーは選択時に確認せず（confirmTerminal=false）、保存時にまとめて確認する */}
                            <StatusSelect
                                variant="drawer"
                                value={data.status}
                                statusOptions={statusOptions}
                                onChange={(v) => setData('status', v)}
                                confirmTerminal={false}
                            />
                        </div>
                        {errors.status && (
                            <p className="mt-1 text-[11px] text-destructive">{errors.status}</p>
                        )}

                        {/* 詳細リンク（案件詳細は未実装のため 404 許容・スコープ外） */}
                        <div className="mt-2 flex gap-3">
                            <Link
                                href={`/engineers/${pipeline.engineer.id}`}
                                className="text-[11px] text-primary/80 underline hover:text-primary/60"
                            >
                                人材詳細
                            </Link>
                            <Link
                                href={`/projects/${pipeline.project.id}`}
                                className="text-[11px] text-primary/80 underline hover:text-primary/60"
                            >
                                案件詳細
                            </Link>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        onClick={onClose}
                        className="ml-2 h-7 w-7 shrink-0 text-muted-foreground [&_svg]:size-3.5"
                        aria-label="閉じる"
                    >
                        <X />
                    </Button>
                </div>

                {/* ボディ */}
                <div className="flex flex-1 flex-col gap-4 overflow-y-auto p-4">
                    {/* スコアサマリ */}
                    <div>
                        <p className="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                            マッチングスコア
                        </p>
                        <div className="flex items-center gap-3.5 rounded border border-border bg-muted/40 p-3.5">
                            <div className="flex shrink-0 flex-col items-center gap-1">
                                <RankBadge rank={pipeline.match_rank} className="text-xs" />
                                <div className="text-3xl font-bold leading-none text-foreground">
                                    {score != null ? score : '—'}
                                    <span className="text-xs font-normal text-muted-foreground"> 点</span>
                                </div>
                            </div>
                            <div className="flex-1">
                                <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        // バー色はランクバッジと配色を統一する（A=緑〜D=赤。未算出はグレー）
                                        className={`h-full rounded-full ${
                                            pipeline.match_rank
                                                ? RANK_BAR_STYLES[pipeline.match_rank] ?? RANK_BAR_FALLBACK_STYLE
                                                : RANK_BAR_FALLBACK_STYLE
                                        }`}
                                        style={{ width: `${score != null ? Math.min(100, Math.max(0, score)) : 0}%` }}
                                    />
                                </div>
                                <p className="mt-1 text-right text-[10px] text-muted-foreground">
                                    {score != null ? `${score} / 100点` : 'スコアなし'}
                                </p>
                            </div>
                        </div>

                        {/* AI スコア算出理由（折りたたみ） */}
                        {pipeline.ai_score_reason && (
                            <AiAccordion title="スコア算出理由">
                                <p className="whitespace-pre-wrap text-xs leading-relaxed text-foreground">
                                    {pipeline.ai_score_reason}
                                </p>
                            </AiAccordion>
                        )}
                    </div>

                    {/*
                     * AI 推薦理由・不足条件（折りたたみ）。
                     * レビュー指摘 #7 対応：従来は両方 null のとき節ごと非表示だったが、
                     * 「項目自体が無い」ように見え Silent Rejection となるため、常に表示し
                     * 未生成時はプレースホルダを出す。
                     */}
                    <AiAccordion title="総合コメント">
                        <p className="mb-1 text-[11px] font-bold text-muted-foreground">推薦理由</p>
                        {pipeline.ai_comment ? (
                            <p className="whitespace-pre-wrap text-xs leading-relaxed text-foreground">
                                {pipeline.ai_comment}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">AI推薦理由は未生成です</p>
                        )}
                        <div className="my-2 h-px bg-border" />
                        <p className="mb-1 text-[11px] font-bold text-destructive">⚠ 不足条件</p>
                        {pipeline.ai_missing ? (
                            <p className="whitespace-pre-wrap text-xs leading-relaxed text-muted-foreground">
                                {pipeline.ai_missing}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                不足条件の指摘はありません
                            </p>
                        )}
                    </AiAccordion>

                    {/* 管理情報フォーム */}
                    <div className="flex flex-col gap-3 rounded border border-border bg-white p-3.5">
                        <p className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                            パイプライン管理情報
                        </p>

                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-bold text-muted-foreground">
                                最終更新日時
                            </span>
                            <span className="py-1 text-xs text-muted-foreground">
                                {formatDateTime(pipeline.updated_at)}
                            </span>
                        </div>

                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-bold text-muted-foreground">
                                担当営業
                            </span>
                            {/* 氏名は最大255文字になり得るため break-all で折り返す（レビュー指摘 #9） */}
                            <span className="break-all py-1 text-xs text-foreground">
                                {pipeline.engineer.main_user?.name ?? '未割当'}
                            </span>
                        </div>

                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] font-bold text-muted-foreground">
                                次回アクション予定日
                            </label>
                            <DateInput
                                value={data.next_action_date}
                                onChange={(v) => setData('next_action_date', v)}
                                className="h-8 w-[180px] bg-white px-2.5 text-xs md:text-xs"
                                {...fieldProps('next_action_date', 'date')}
                            />
                            {(clientErrors.next_action_date ?? errors.next_action_date) && (
                                <p className="text-[11px] text-destructive">
                                    {clientErrors.next_action_date ?? errors.next_action_date}
                                </p>
                            )}
                        </div>

                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] font-bold text-muted-foreground">
                                顧客コメント
                            </label>
                            {/* 過大な入力による PostTooLargeException を避けるため、バックエンドの max:1000 と揃えてフロントでも制限する */}
                            <Textarea
                                rows={2}
                                value={data.client_comment}
                                onChange={(e) => setData('client_comment', e.target.value)}
                                maxLength={1000}
                                className="min-h-0 w-full resize-y bg-white px-2.5 py-2 text-xs leading-relaxed md:text-xs"
                            />
                            {errors.client_comment && (
                                <p className="text-[11px] text-destructive">{errors.client_comment}</p>
                            )}
                        </div>

                        <div className="flex flex-col gap-1">
                            <label className="text-[10px] font-bold text-muted-foreground">NG理由</label>
                            {/* 過大な入力による PostTooLargeException を避けるため、バックエンドの max:1000 と揃えてフロントでも制限する */}
                            <Textarea
                                rows={2}
                                value={data.ng_reason}
                                onChange={(e) => setData('ng_reason', e.target.value)}
                                placeholder="見送り・不成立の場合に記入"
                                maxLength={1000}
                                className="min-h-0 w-full resize-y bg-white px-2.5 py-2 text-xs leading-relaxed md:text-xs"
                            />
                            {errors.ng_reason && (
                                <p className="text-[11px] text-destructive">{errors.ng_reason}</p>
                            )}
                        </div>
                    </div>
                </div>

                {/* フッタ */}
                <div className="flex shrink-0 items-center gap-2 border-t border-border bg-muted/40 p-4">
                    <Button className="h-9 flex-1" onClick={handleSave} disabled={processing}>
                        <Check className="mr-1.5 h-3.5 w-3.5" />
                        {processing ? '保存中...' : '保存する'}
                    </Button>
                    {isAdmin && (
                        <div className="flex flex-col items-end gap-0.5">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setShowDeleteConfirm(true)}
                                disabled={processing || isDeleting}
                                className="border-destructive text-destructive hover:bg-destructive/5"
                            >
                                <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                                削除
                            </Button>
                        </div>
                    )}
                </div>
            </div>

            {/* 終了ステータスへ変更しての保存確認（完了済みタブへ移動＝不可逆）。保存時に表示する */}
            <ConfirmDialog
                open={showTerminalConfirm}
                title="完了済みへ移動しますか？"
                description="このステータスで保存すると完了済みタブへ移動し、元に戻せません。"
                confirmLabel="移動する"
                confirmVariant="default"
                onConfirm={() => {
                    setShowTerminalConfirm(false);
                    doSave();
                }}
                onCancel={() => setShowTerminalConfirm(false)}
            />

            {/* 削除確認（共通 ConfirmDialog。人材詳細の削除と統一） */}
            <ConfirmDialog
                open={showDeleteConfirm}
                title="このパイプラインを削除しますか？"
                description={
                    <>
                        <strong>{pipeline.engineer.name}</strong> のパイプラインを削除します。この操作は取り消せません。
                    </>
                }
                confirmLabel="削除する"
                processingLabel="削除中..."
                processing={isDeleting}
                onConfirm={confirmDelete}
                onCancel={() => setShowDeleteConfirm(false)}
            />
        </>
    );
}

/**
 * ドロワー内の折りたたみブロック（AI テキスト用）。shadcn Accordion（Radix）で実装。
 * 各ブロックは独立して開閉できるよう type="single" collapsible の単一 Item として構成する。
 * 見た目は従来の自作 Accordion に合わせる（AI バッジ・淡色ヘッダ・小さめのシェブロン）。
 *
 * shrink-0：スクロール可能な flex カラム直下で overflow-hidden により min-height:0 となり
 * ヘッダーごと潰れる事故を防ぐ（レビュー指摘 #7）。
 */
function AiAccordion({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <Accordion
            type="single"
            collapsible
            className="mt-2 shrink-0 overflow-hidden rounded border border-border"
        >
            <AccordionItem value="item" className="border-b-0">
                <AccordionTrigger className="rounded-none bg-muted/60 px-3 py-1.5 text-[11px] font-semibold text-muted-foreground hover:bg-muted hover:no-underline [&>svg]:size-3">
                    <span className="flex items-center gap-1.5">
                        {/* AI バッジ色は人材詳細（Engineers/Show の職務要約）と統一 */}
                        <span className="rounded-sm bg-purple-600 px-1.5 py-px text-[9px] font-bold text-white">
                            AI
                        </span>
                        {title}
                    </span>
                </AccordionTrigger>
                <AccordionContent className="bg-white p-2.5">{children}</AccordionContent>
            </AccordionItem>
        </Accordion>
    );
}
