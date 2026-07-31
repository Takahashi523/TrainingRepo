import CsvUploader from '@/Components/Common/CsvUploader';
import LoadingOverlay from '@/Components/Common/LoadingOverlay';
import ImportResultBanner from '@/Components/Csv/ImportResultBanner';
import ImportResultModal from '@/Components/Csv/ImportResultModal';
import UserIdLegendPopover from '@/Components/Csv/UserIdLegendPopover';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useToast } from '@/hooks/use-toast';
import { CsvResource, ImportError, ImportResult } from '@/types/csv';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Props {
    /** 対象リソース（field ラベル解決・成功文言に使う）。 */
    resource: CsvResource;
    /** インポート先の Ziggy ルート名（例：'csv.engineers.import'）。 */
    importRouteName: string;
    /** 対象名（見出し・説明の主語。例：「人材」「案件」）。 */
    resourceLabel: string;
    /** 担当者ID凡例に渡す users（GET /csv Props 由来）。 */
    users: { id: number; name: string }[];
    /**
     * このタブが表示中か。両タブを forceMount で常設し結果を独立保持するため、
     * 非表示になった瞬間にモーダルを閉じる（結果 state は破棄しない＝requirements の「タブ切替＝閉じる/保持」）。
     * モーダルは Portal で body 直下に出るため、タブを hidden にしても自動では消えない。ここで明示的に閉じる。
     */
    active: boolean;
}

/** 成功時 flash から importResult を安全に取り出す（Inertia の page.props を最小限にナローイング）。 */
function readImportResult(props: unknown): ImportResult | null {
    if (typeof props !== 'object' || props === null) return null;
    const flash = (props as { flash?: unknown }).flash;
    if (typeof flash !== 'object' || flash === null) return null;
    const result = (flash as { importResult?: unknown }).importResult;
    if (typeof result !== 'object' || result === null) return null;
    return result as ImportResult;
}

/** errors バッグの値（string | string[]）から先頭の文字列を取り出す。 */
function firstError(value: string | string[] | undefined): string | null {
    if (value === undefined) return null;
    return Array.isArray(value) ? (value[0] ?? null) : value;
}

/**
 * CSV インポート欄（WF_11）。人材・案件で共通のインポート UI と Inertia 配線を担う。
 *
 * サーバー契約（design §2-6）：
 * - 送信：useForm（forceFormData:true・field 名 file）。`<form>` タグ不使用・processing で二重送信防止。
 * - 成功：302 redirect（csv.index）＋ flash.importResult → onSuccess でトースト（新規/更新件数）。
 * - 内容エラー：422・errors.importErrors（JSON 文字列）→ onError で parse し結果モーダルを開く。
 * - ファイルエラー：422・errors.file（単一メッセージ）→ アップロード欄直下に InputError。
 *
 * 結果の保持/クリア（requirements §4 の表）：
 * - 閉じる/✕/Esc＝非表示のみ（結果は保持）。外クリック＝閉じない。
 * - 結果クリアは「新ファイル選択」「ファイル取消」時のみ。実行後は成功・失敗ともファイル選択をクリア。
 */
export default function CsvImportSection({
    resource,
    importRouteName,
    resourceLabel,
    users,
    active,
}: Props) {
    const { toast } = useToast();
    const form = useForm<{ file: File | null }>({ file: null });

    // 保持する結果（モーダルを閉じても破棄しない）。エラー時のみ持つ（成功はトースト通知）。
    const [errorResult, setErrorResult] = useState<ImportError[] | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    // ファイルレベルエラー（mime/サイズ/文字コード/行数超過）。アップロード欄直下に出す。
    const [fileError, setFileError] = useState<string | null>(null);

    // タブが非表示になったらモーダルを閉じる（結果 state は保持）。
    useEffect(() => {
        if (!active) setModalOpen(false);
    }, [active]);

    /** 結果とモーダルをまとめてクリアする（新ファイル選択・ファイル取消の起点）。 */
    const clearResult = () => {
        setErrorResult(null);
        setModalOpen(false);
        setFileError(null);
    };

    const handleSelect = (file: File) => {
        // 新しいファイル選択＝再インポートの起点。前回結果はクリアする（requirements 表）。
        clearResult();
        form.setData('file', file);
    };

    const handleClear = () => {
        // ユーザーが「✕ 取り消す」。結果もクリアする（requirements 表）。
        clearResult();
        form.setData('file', null);
    };

    const handleSubmit = () => {
        if (form.data.file === null || form.processing) return;

        form.post(route(importRouteName), {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => setFileError(null),
            onSuccess: (page) => {
                const result = readImportResult(page.props);
                const summary = result?.summary;
                toast({
                    variant: 'success',
                    duration: 4000,
                    description: summary
                        ? `${resourceLabel}CSVの取り込みが完了しました：新規追加 ${summary.created}件 / 更新 ${summary.updated}件`
                        : `${resourceLabel}CSVの取り込みが完了しました。`,
                });
                // 成功したら前回のエラー結果は破棄する（新結果で置換）。
                setErrorResult(null);
                setModalOpen(false);
            },
            onError: (errors) => {
                const importErrorsRaw = firstError(
                    (errors as Record<string, string | string[]>).importErrors,
                );
                const fileErrorRaw = firstError(
                    (errors as Record<string, string | string[]>).file,
                );

                if (importErrorsRaw !== null) {
                    try {
                        const parsed = JSON.parse(importErrorsRaw) as ImportError[];
                        setErrorResult(parsed);
                        setModalOpen(true);
                        setFileError(null);
                    } catch {
                        // 想定外の形式（サーバー不整合）。握りつぶさずトーストで知らせる。
                        setErrorResult(null);
                        setModalOpen(false);
                        toast({
                            variant: 'destructive',
                            duration: 5000,
                            description: 'インポート結果の解析に失敗しました。時間をおいて再度お試しください。',
                        });
                    }
                } else if (fileErrorRaw !== null) {
                    // ファイルレベルエラーはモーダルではなく欄直下に表示する。前回のエラー結果は置換（クリア）。
                    setFileError(fileErrorRaw);
                    setErrorResult(null);
                    setModalOpen(false);
                }
            },
            // 実行後は成功・失敗ともファイル選択をクリアする（File スナップショット対策・requirements §4）。
            // 結果（errorResult）は保持したままにするため、ここでは file のみ null に戻す。
            onFinish: () => form.setData('file', null),
        });
    };

    return (
        <>
            <Card className="overflow-hidden">
                {/* セクションヘッダー（タイトル＋担当者ID凡例） */}
                <div className="flex items-center gap-2 border-b border-border bg-muted/40 px-5 py-3">
                    <h2 className="text-sm font-bold text-foreground">
                        インポート（登録 / 更新）
                    </h2>
                    <div className="ml-auto">
                        <UserIdLegendPopover users={users} />
                    </div>
                </div>

                <div className="space-y-4 p-5">
                    <p className="text-xs leading-relaxed text-muted-foreground">
                        CSVファイルをアップロードして、{resourceLabel}データを一括登録または更新します。
                        システムIDが含まれる行は既存データを上書き更新、IDが空の行は新規追加として処理します。
                        <br />※ エクスポートしたCSVを編集して再インポートする運用を想定しています。
                    </p>

                    <CsvHint />

                    <CsvUploader
                        file={form.data.file}
                        onSelect={handleSelect}
                        onClear={handleClear}
                        disabled={form.processing}
                        errorMessage={fileError ?? undefined}
                    />

                    {/* 保持中のエラー結果バナー（モーダルを閉じても残る／「詳細を表示」で再オープン） */}
                    {errorResult !== null && !modalOpen && (
                        <ImportResultBanner
                            errors={errorResult}
                            onReopen={() => setModalOpen(true)}
                        />
                    )}

                    {/* 実行ボタン行 */}
                    <div className="flex items-center gap-3 border-t border-border pt-4">
                        <span className="mr-auto text-[11px] text-muted-foreground">
                            ※ 実行前にバックアップを取得することを推奨します
                        </span>
                        <Button
                            type="button"
                            onClick={handleSubmit}
                            disabled={form.data.file === null || form.processing}
                            className="h-9"
                        >
                            インポート実行
                        </Button>
                    </div>
                </div>
            </Card>

            {/* 実行中オーバーレイ（AIバッジなしの汎用・キャンセル不可＝書き込み処理のため） */}
            <LoadingOverlay show={form.processing} message="CSVを取り込んでいます…" />

            {/* 結果モーダル（エラー時。閉じても結果は保持） */}
            {errorResult !== null && (
                <ImportResultModal
                    open={modalOpen}
                    resource={resource}
                    errors={errorResult}
                    onClose={() => setModalOpen(false)}
                />
            )}
        </>
    );
}

/**
 * CSV作成のヒント（WF_11 v1.2・ユーザー向け）。人材/案件タブ共通の静的ガイド。
 * enum は内部値（英字コード）限定（O-2）・日付は YYYY-MM-DD（O-10）等の注意を周知する。
 */
function CsvHint() {
    return (
        <div className="space-y-1.5 rounded-md border border-sky-200 bg-sky-50 px-3.5 py-3 text-[11px] leading-relaxed text-sky-900">
            <p className="font-bold">📝 CSV作成のヒント</p>
            <ul className="list-disc space-y-0.5 pl-4">
                <li>
                    Excel では「<b>CSV UTF-8（コンマ区切り）</b>」形式で保存してください（文字化け防止）。
                </li>
                <li>
                    カンマ・改行・引用符を含む項目は <b>&quot; &quot;（ダブルクオート）で囲みます</b>。
                    Excel が自動対応するため通常は意識不要です。<b>シングルクオート（&apos;）で囲む必要はありません。</b>
                </li>
                <li>
                    <b>空行（何も入力していない行）は無視</b>されます。スペースだけの行は不正な行として扱われます。
                </li>
                <li>
                    <b>一度に取り込めるのは 5,000 行までです。</b>超える場合はファイルを分割してください。
                </li>
                <li>
                    担当者ID（主担当ID / サブ担当ID）が分からない場合は右上の「<b>担当者ID一覧</b>」で確認できます。
                </li>
                <li>
                    エクスポートCSVの「主担当名 / サブ担当名」列は参照用で、<b>取り込み時は無視</b>されます（担当者はID列で決まります）。
                </li>
                <li>
                    <b>ステータス・商流・稼働形態などは英字コードのまま入力してください</b>（日本語で書き換えると取り込めません）。下の対応表を参照。
                </li>
                <li>
                    <b>日付は <code className="rounded bg-sky-100 px-1">YYYY-MM-DD</code> 形式で入力してください</b>
                    （例：2026-04-01）。Excel の自動変換（2026/4/1 等）や ID の指数表記・先頭ゼロ落ちに注意。
                </li>
                <li>
                    <b>CSV作業中は、対象の人材・案件を画面から編集しないでください。</b>取り込み時に他の人の変更を上書きする場合があります。
                </li>
            </ul>
            <div className="mt-1.5 border-t border-dashed border-sky-200 pt-1.5 text-[10px] text-sky-800">
                <b>コード対応：</b>
                人材ステータス＝ <code>proposable</code>提案可 / <code>interviewing</code>面談中 /{' '}
                <code>not_proposable</code>提案不可。 案件ステータス＝ <code>open</code>募集中 /{' '}
                <code>closed</code>終了 / <code>pending</code>ペンディング。 商流＝ <code>prime</code>プライム /{' '}
                <code>secondary</code>2次 / <code>tertiary</code>3次 / <code>other</code>その他。 稼働形態＝{' '}
                <code>onsite</code>常駐 / <code>hybrid</code>一部リモート可 / <code>remote</code>フルリモート。
                （0/1 で入力：勤務形態フラグ・各工程経験・顧客折衝）
            </div>
        </div>
    );
}
