import AiLoadingOverlay from '@/Components/Common/AiLoadingOverlay';
import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import Rate from '@/Components/Common/Rate';
import SkillTag from '@/Components/Common/SkillTag';
import StatusBadge from '@/Components/Common/StatusBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import MatchCard from '@/Components/Matching/MatchCard';
import MatchDrawer from '@/Components/Matching/MatchDrawer';
import { Button } from '@/Components/ui/button';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { emptyText } from '@/lib/emptyValue';
import { PageProps } from '@/types';
import { MatchingEmptyReason, MatchingShowPageProps } from '@/types/matching';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, PackageX, RefreshCw, SearchX } from 'lucide-react';
import { ComponentType, useEffect, useRef, useState } from 'react';

type Props = PageProps<MatchingShowPageProps>;

// 結果0件時の空状態を理由ごとに出し分ける（アイコン・見出し・説明）。
// キーはサーバー MatchingController の EMPTY_* 定数（emptyReason）と一致させる。
const EMPTY_STATES: Record<
    MatchingEmptyReason,
    { icon: ComponentType<{ className?: string }>; title: string; description: string }
> = {
    // #1・#2：そもそも条件に合う募集中案件が無い（正常系の0件）。
    no_match: {
        icon: SearchX,
        title: 'マッチする案件がありませんでした',
        description: '条件に合う募集中の案件が見つかりませんでした。',
    },
    // #3：エンジン通信失敗。別途 flash.error のトーストも表示される。
    engine_error: {
        icon: AlertTriangle,
        title: 'マッチングを実行できませんでした',
        description: 'マッチングエンジンとの通信に失敗しました。時間をおいて再度お試しください。',
    },
    // #4：マッチはあったが、対象案件が全て削除され表示できるものが無くなった
    //（掲載停止 closed/pending は一覧に残して無効表示するため、ここには該当しない）。
    unavailable: {
        icon: PackageX,
        title: '表示できる案件がありませんでした',
        description: 'マッチした案件は削除により表示できません。',
    },
};

export default function Show({
    engineer,
    results: resultsProp,
    emptyReason: emptyReasonProp,
    targetState,
}: Props) {
    // results / emptyReason はローカル state で保持する。パイプライン追加直後の back では
    // サーバーが results=null を返す（＝再スコアリングしない・#4）。その場合は既存表示を維持し、
    // 追加カードのみ「追加済み」に楽観更新する。props が非 null のとき（通常遷移）だけ同期する。
    const [results, setResults] = useState(resultsProp ?? []);
    const [emptyReason, setEmptyReason] = useState<MatchingEmptyReason | null>(emptyReasonProp);

    useEffect(() => {
        if (resultsProp !== null) {
            setResults(resultsProp);
            setEmptyReason(emptyReasonProp);
            return;
        }

        // 据え置き指示（results=null）でも理由が来ているとき（＝エンジン通信失敗・#52）は理由だけ更新する。
        // 一覧が0件のまま再マッチングに失敗すると、据え置くべき中身が無いのに前回の理由（例：no_match＝
        // 「条件に合う募集中の案件が見つかりませんでした」）が残り、確認できていない事実を断定してしまう。
        // 追加直後の back は emptyReason=null で来るため、この分岐に入らず従来の空状態を壊さない。
        if (emptyReasonProp !== null) {
            setEmptyReason(emptyReasonProp);
        }
    }, [resultsProp, emptyReasonProp]);

    // 選択中カードの index（ドロワー開閉）。null で閉じている。
    const [selected, setSelected] = useState<number | null>(null);

    // 追加失敗の back：エンジンを再実行しない代わりに、試行した案件1件だけ最新状態へ差分更新する。
    // これで掲載停止・上限到達・追加済みがカードに反映され、ドロワーの追加ボタンも無効化される
    // （古い addable 表示のまま再度押せてしまう問題を防ぐ・#4 楽観更新の失敗パス版）。
    useEffect(() => {
        if (!targetState) return;

        if (!targetState.exists) {
            // ハード削除された案件は表示できないため一覧から除去し、開いていたドロワーは閉じる
            // （selected は index のため、要素除去でズレる前に閉じる）。
            setResults((prev) => prev.filter((r) => r.project.id !== targetState.project_id));
            setSelected(null);
            return;
        }

        setResults((prev) =>
            prev.map((r) =>
                r.project.id === targetState.project_id
                    ? {
                          ...r,
                          is_in_pipeline: targetState.is_in_pipeline,
                          is_available: targetState.is_available,
                          is_project_full: targetState.is_project_full,
                          project: { ...r.project, status_label: targetState.status_label },
                      }
                    : r,
            ),
        );
    }, [targetState]);

    const current = selected != null ? results[selected] : null;

    // 追加成功時：サーバー再描画に頼らず、対象案件のカードを「追加済み」に楽観更新する（#4）。
    // 同一エンジニアのマッチング結果に同じ案件は1件しか出ないため、対象カードのみ更新すれば十分。
    const handleAdded = (projectId: number) => {
        setResults((prev) =>
            prev.map((r) => (r.project.id === projectId ? { ...r, is_in_pipeline: true } : r)),
        );
    };

    // 工程経験を人材登録・一覧と同じ ProcessCheckboxGroup で表示する（readOnly）。人材は has_experience。
    const { phaseList, phaseValues } = buildProcessPhaseProps(engineer.phases, 'has_experience');

    // 再マッチング（#52）：AI を再実行して一覧を最新化する明示的オプトイン導線。
    // 自動再実行は行わない（コスト・並び替わり・成功直後の空状態化を避けるため）方針は維持し、
    // ユーザーが押したときだけ回す。リロード/ブラウザバックしか最新化手段が無い状態を解消する。
    const [isRerunning, setIsRerunning] = useState(false);

    // マッチングは読み取り専用（DB保存なし）のため、途中キャンセルは安全（副作用が残らない）。
    // Inertia visit の cancel トークンを保持し、オーバーレイのキャンセルボタンから中断する（人材詳細と同方式）。
    const rerunCancel = useRef<(() => void) | null>(null);

    // キャンセル時に開いていたドロワーへ戻すための退避先（キャンセル＝何も起きなかったことにする）。
    const selectedBeforeRerun = useRef<number | null>(null);

    const handleRerun = () => {
        // 再取得で並びが変わると、index で選択しているドロワーが別案件の内容に化ける。
        // 実行前に必ず閉じ、ユーザーが見ている対象と中身がズレた状態を作らない
        // （マウスでは背景幕に阻まれるが、ドロワーはフォーカストラップを持たないためキーボードで到達できる）。
        selectedBeforeRerun.current = selected;
        setSelected(null);

        // サーバーに到達できない通信断は onError にも flash にも乗らないが、AuthenticatedLayout が
        // exception を全画面共通で購読してトーストを出すため（#84）、ここでは購読しない
        // （到達済みのエンジン失敗はサーバーが flash.error で通知するので、そちらとも重複しない）。

        // 現在 URL への素の GET。preserve_matching_results フラグが無いためサーバーがエンジンを再実行する。
        // reload() は preserveState を強制するため、通信失敗時にサーバーが返す results=null（＝既存表示の
        // 据え置き指示）がそのまま効く（コンポーネントが再マウントされると据え置きが成立しない）。
        // 併せて async 実行のため、二重実行はボタンの disabled とモーダルオーバーレイで防ぐ。
        router.reload({
            // reload() は async 実行のため Inertia の既定（showProgress = !async）では進捗バーが出ない。
            // 人材詳細からの初回マッチング（router.get）では出るので、明示的に有効化して体裁を揃える。
            // 全画面オーバーレイは「何が起きているか」を、進捗バーは「リクエストが飛んでいること」を伝える。
            showProgress: true,
            onStart: () => setIsRerunning(true),
            // onFinish は成功・失敗・キャンセルすべてで発火するため、後片付けはここに集約する。
            onFinish: () => {
                setIsRerunning(false);
                rerunCancel.current = null;
            },
            onCancelToken: (token) => {
                rerunCancel.current = token.cancel;
            },
        });
    };

    // オーバーレイのキャンセル（ボタン / ESC）。一覧は一切変わらないので、開いていたドロワーも戻して
    // 「何も起きなかった」状態にする（実行前に閉じるのは並び替わり対策であり、キャンセルには不要なため）。
    const handleRerunCancel = () => {
        // 応答が着地した後（onFinish でトークンを捨てた後）は復元しない。
        // AiLoadingOverlay の Content は閉じるアニメーション（duration-200）の間マウントが残るため、
        // 一覧が差し替わった直後の ESC / クリックがここに届き得る。そのとき復元すると
        // 「旧一覧に対する index」で新一覧のドロワーを開くことになり、実行前に setSelected(null) して
        // 防いだはずの「見ている対象と中身がズレる」状態を作ってしまう。
        if (!rerunCancel.current) return;

        rerunCancel.current();
        setSelected(selectedBeforeRerun.current);
    };

    return (
        <AuthenticatedLayout>
            <Head title="マッチング結果" />

            {/* AI 再実行中（Python 同期計算・数秒）に全画面で計算中を表示する。文言・見た目は遷移元
                （人材詳細のマッチング実行）と同一にし、初回ロードと再実行で体験を割らない。
                マッチングは読み取り専用でキャンセルが安全なため onCancel を渡す（visit を中断）。 */}
            <AiLoadingOverlay
                show={isRerunning}
                message="AIがマッチングを計算しています…"
                onCancel={handleRerunCancel}
            />

            {/* WF_09：ヘッダ・対象人材サマリーは上部固定、下の結果一覧のみスクロール。
                進捗管理・人材一覧と同じく p-6 を -m-6 で打ち消し、画面全高（h-screen）の flex カラムにする。 */}
            <div className="relative -m-6 flex h-screen flex-col overflow-hidden">
                {/* ページヘッダー（WF_09：タイトル＋サブタイトル。#52 で右側に再マッチングを追加） */}
                <div className="flex shrink-0 items-center justify-between border-b border-border bg-white px-10 py-4">
                    <div>
                        <h1 className="text-lg font-bold text-foreground">マッチング結果</h1>
                        <p className="mt-0.5 text-xs text-muted-foreground">対象人材にマッチする案件をAIスコアの高い順に表示します</p>
                    </div>
                    {/* 実行中は disabled にして二重実行を防ぐ（オーバーレイでも背後の操作は遮断される）。 */}
                    <Button
                        variant="outline"
                        size="sm"
                        className="h-8 shrink-0 gap-1.5 text-xs"
                        onClick={handleRerun}
                        disabled={isRerunning}
                    >
                        <RefreshCw className="h-3.5 w-3.5" />
                        再マッチング
                    </Button>
                </div>

                {/* 対象人材サマリー（WF_09：薄グレーの帯・下境界のみ */}
                <div className="shrink-0 border-b border-border bg-muted/40 px-10 py-3">
                    {/* 上段：氏名 + ステータスバッジ（最重要属性なので氏名の直後に置き、同一性と状態をまとめて読ませる） */}
                    <div className="flex flex-wrap items-center gap-2">
                        {/* 氏名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（TruncatedText）。
                            氏名・案件名は同じ行に他項目が無く極端に長くもならない想定のため、max-w は付けない。 */}
                        <TruncatedText
                            as="p"
                            text={engineer.name}
                            className="min-w-0 text-base font-bold text-foreground"
                        />
                        <StatusBadge status={engineer.status} className="shrink-0" />
                    </div>

                    {/* 年齢・最寄駅・希望単価・勤務形態を、マッチングカードのメタと同じ型2（ラベル語なし・｜区切りの横一列）で表示する。
                        項目名は MetaItem が sr-only で支援技術に渡す。未指定も同じ流儀で項目名入りトークンにし
                        （入力済み属性＝未設定／柔軟に決まり得る条件＝未定）、カードと語彙を揃える。 */}
                    <MetaRow className="mt-1.5">
                        <MetaItem field="age" valueHasFieldName={engineer.age == null}>
                            {engineer.age != null ? `${engineer.age}歳` : emptyText('age', true)}
                        </MetaItem>
                        {/* 最寄駅・路線名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（max-w で幅を抑える）。 */}
                        <MetaItem
                            field="nearestStation"
                            valueHasFieldName={!engineer.nearest_station}
                            className="max-w-[18rem]"
                        >
                            <TruncatedText
                                text={engineer.nearest_station || emptyText('nearestStation', true)}
                                className="min-w-0 max-w-[8rem]"
                            />
                            <TruncatedText
                                text={
                                    engineer.nearest_line
                                        ? `（${engineer.nearest_line}）`
                                        : `（${emptyText('nearestLine', true)}）`
                                }
                                className="min-w-0 max-w-[8rem]"
                            />
                        </MetaItem>
                        <MetaItem field="availableFrom" valueHasFieldName={!engineer.available_from}>
                            {engineer.available_from ? engineer.available_label : emptyText('availableFrom', true)}
                        </MetaItem>
                        {/* 希望単価は単一値。案件の単価（レンジ）と単位「万円」の見せ方を揃えるため Rate に載せる。 */}
                        <MetaItem field="desiredRate" valueHasFieldName={engineer.desired_rate == null}>
                            <Rate value={engineer.desired_rate} variant="plain" withFieldName />
                        </MetaItem>
                        <MetaItem field="workStyle" valueHasFieldName={engineer.work_styles.length === 0}>
                            {engineer.work_styles.length > 0
                                ? engineer.work_styles.map((w) => w.name).join(' / ')
                                : emptyText('workStyle', true)}
                        </MetaItem>
                    </MetaRow>

                    {/* スキル：マッチングカードと同じくラベル（見出し）なしでタグを直接並べる。
                        空でも高さを揃えるためプレースホルダタグを出す（カードのスキル行と同じ流儀）。 */}
                    {engineer.skills.length > 0 ? (
                        // detail は一覧・簡易表示の規約どおり非表示（詳細は SkillTagDetail を使う人材詳細画面で確認できる）。
                        <CollapsibleTagRow className="mt-1.5">
                            {engineer.skills.map((s, i) => (
                                <SkillTag key={`${s.label ?? ''}-${i}`} label={s.label ?? ''} className="text-muted-foreground" />
                            ))}
                        </CollapsibleTagRow>
                    ) : (
                        <div className="mt-1.5">
                            <span className="text-[11px] text-muted-foreground">{emptyText('skills', true)}</span>
                        </div>
                    )}

                    {/* 工程経験：マッチングカードと同じくラベルなしで ProcessCheckboxGroup を直接表示（サイズ縮小ラッパーも共通）。 */}
                    <div className="mt-2 [&_button]:h-3.5 [&_button]:w-3.5 [&_label]:text-[11px] [&_svg]:h-2.5 [&_svg]:w-2.5">
                        <ProcessCheckboxGroup phases={phaseList} values={phaseValues} readOnly labelClassName="text-muted-foreground" />
                    </div>
                </div>

                {/* 結果一覧（WF_09 の list-area：薄グレー背景・この領域のみスクロール。人材一覧カードの一覧エリアと同じ bg-muted/30） */}
                <div className="flex-1 overflow-y-auto bg-muted/30 px-10 py-4">
                    {results.length === 0 ? (
                        (() => {
                            // 結果0件の理由で出し分ける。emptyReason は0件時は必ず入るが、
                            // 万一 null でも no_match にフォールバックして必ず何か表示する。
                            const empty = EMPTY_STATES[emptyReason ?? 'no_match'];
                            const EmptyIcon = empty.icon;
                            return (
                                <div className="flex flex-col items-center justify-center rounded-md border border-dashed border-border py-16 text-center">
                                    <EmptyIcon className="mb-2 h-8 w-8 text-muted-foreground" />
                                    <p className="text-sm font-semibold text-foreground">{empty.title}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">{empty.description}</p>
                                </div>
                            );
                        })()
                    ) : (
                        <>
                            {/* 件数表示：人材一覧・進捗管理完了タブと同じ体裁（一覧先頭の小テキスト・件数を強調）。 */}
                            <div className="mb-3 text-xs text-muted-foreground">
                                スコア上位 <strong className="text-foreground">{results.length}</strong> 件を表示
                            </div>
                            <div className="space-y-2.5">
                                {results.map((result, i) => (
                                    <MatchCard
                                        key={result.project.id}
                                        result={result}
                                        selected={selected === i}
                                        onSelect={() => setSelected(i)}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* 右ドロワー */}
            {current && (
                <>
                    <div
                        className="fixed inset-0 z-40 bg-black/30"
                        onClick={() => setSelected(null)}
                        aria-hidden="true"
                    />
                    <div className="fixed inset-y-0 right-0 z-50 w-full max-w-md border-l border-border bg-white shadow-xl">
                        <MatchDrawer
                            key={current.project.id}
                            result={current}
                            engineerId={engineer.id}
                            onAdded={() => handleAdded(current.project.id)}
                            onClose={() => setSelected(null)}
                        />
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
