import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import MetaRow, { MetaItem } from '@/Components/Common/MetaRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Common/ProcessCheckboxGroup';
import SkillTag from '@/Components/Common/SkillTag';
import StatusBadge from '@/Components/Common/StatusBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import MatchCard from '@/Components/Matching/MatchCard';
import MatchDrawer from '@/Components/Matching/MatchDrawer';
import { Sheet, SheetContent } from '@/Components/ui/sheet';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { emptyText } from '@/lib/emptyValue';
import { PageProps } from '@/types';
import { MatchingEmptyReason, MatchingShowPageProps } from '@/types/matching';
import { Head } from '@inertiajs/react';
import { AlertTriangle, PackageX, SearchX } from 'lucide-react';
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

    // ドロワー（Sheet）の Portal 先。WF_09 どおりドロワー本体をコンテンツ領域内に収め、
    // サイドバーの上に乗せないため、body ではなくこのページのコンテナへ描画する。
    // ref ではなく state で保持するのは、ref 代入では再レンダリングが起きず、
    // 初回描画時に container が null（＝ body へフォールバック）のままになるため。
    const [drawerContainer, setDrawerContainer] = useState<HTMLDivElement | null>(null);

    // ドロワーを開いた起点の要素。閉じたときにここへフォーカスを戻す。
    // Sheet は SheetTrigger 経由で開いていないため、Radix は復帰先を知らない（放置するとフォーカスが body に落ち、
    // キーボード操作では一覧の先頭から辿り直しになる）。開いた瞬間の activeElement を自前で覚えておく。
    const openerRef = useRef<HTMLElement | null>(null);

    const openDrawer = (index: number) => {
        openerRef.current = document.activeElement as HTMLElement | null;
        setSelected(index);
    };

    return (
        <AuthenticatedLayout>
            <Head title="マッチング結果" />

            {/* WF_09：ヘッダ・対象人材サマリーは上部固定、下の結果一覧のみスクロール。
                進捗管理・人材一覧と同じく p-6 を -m-6 で打ち消し、画面全高（h-screen）の flex カラムにする。 */}
            <div
                ref={setDrawerContainer}
                // ドロワーを閉じたときの復帰先（起点のカードが消えている場合）。マウスでは焦点化しない
                tabIndex={-1}
                className="relative -m-6 flex h-screen flex-col overflow-hidden outline-none"
            >
                {/* ページヘッダー（WF_09：タイトル＋サブタイトルのみ。アクションボタンは持たない） */}
                <div className="shrink-0 border-b border-border bg-white px-10 py-4">
                    <h1 className="text-lg font-bold text-foreground">マッチング結果</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">対象人材にマッチする案件をAIスコアの高い順に表示します</p>
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
                        <MetaItem field="age">
                            {engineer.age != null ? `${engineer.age}歳` : emptyText('age', true)}
                        </MetaItem>
                        {/* 最寄駅・路線名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（max-w で幅を抑える）。 */}
                        <MetaItem field="nearestStation" className="max-w-[18rem]">
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
                        <MetaItem field="availableFrom">
                            {engineer.available_from ? engineer.available_label : emptyText('availableFrom', true)}
                        </MetaItem>
                        <MetaItem field="desiredRate">
                            {engineer.desired_rate != null
                                ? `${engineer.desired_rate}万円`
                                : emptyText('desiredRate', true)}
                        </MetaItem>
                        <MetaItem field="workStyle">
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
                                        onSelect={() => openDrawer(i)}
                                    />
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>

            {/* 右ドロワー（shadcn Sheet＝Radix Dialog。ESC・フォーカストラップ・スクロールロックを標準で得る）。
                ・パネルは container 指定＋absolute でコンテンツ領域内に収め、サイドバーの上に乗せない（WF_09 の .drawer）
                ・幕は fixed のまま画面全体に敷く。modal によりサイドバーは操作できなくなるため、
                  暗くして「今は操作できない」ことを見た目でも示す（明るいまま押せない状態にしない）
                ・閉じるは onOpenChange 一本に集約する（ESC・幕クリック・ヘッダーの ✕ すべてここを通る） */}
            <Sheet
                // container が確定するまで開かない（body へ Portal されてサイドバーに被る一瞬を作らない）
                open={!!current && drawerContainer !== null}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <SheetContent
                    container={drawerContainer}
                    overlayClassName="z-20 bg-black/25"
                    className="absolute inset-y-0 right-0 z-30 h-full w-full max-w-md gap-0 border-l border-border bg-white p-0 shadow-xl sm:max-w-md"
                    showCloseButton={false}
                    // ドロワーは説明文を持たないため、存在しない id を指す aria-describedby を出さない
                    aria-describedby={undefined}
                    tabIndex={-1}
                    onOpenAutoFocus={(event) => {
                        // 既定では最初の tabbable（ヘッダーの ✕）に乗る。パネル自身に当てて
                        // SheetTitle（案件名）から読ませ、進捗管理側と挙動を揃える。
                        event.preventDefault();
                        (event.currentTarget as HTMLElement).focus();
                    }}
                    onCloseAutoFocus={(event) => {
                        // Radix は SheetTrigger 経由で開いていないと復帰先を知らず、既定のまま抜けると
                        // フォーカスが body に落ちる。起点が残っていればそこへ、消えていれば一覧コンテナへ戻す。
                        event.preventDefault();
                        const opener = openerRef.current;
                        (opener?.isConnected ? opener : drawerContainer)?.focus();
                    }}
                >
                    {/* 別カードを選び直したときにフォーム状態を持ち越さないため、案件IDで再マウントする */}
                    {current && (
                        <MatchDrawer
                            key={current.project.id}
                            result={current}
                            engineerId={engineer.id}
                            onAdded={() => handleAdded(current.project.id)}
                            onClose={() => setSelected(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>
        </AuthenticatedLayout>
    );
}
