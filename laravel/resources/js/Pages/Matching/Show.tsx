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
import { Sheet, SheetContent } from '@/Components/ui/sheet';
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

    // ドロワー（Sheet）の Portal 先。WF_09 どおりドロワー本体をコンテンツ領域内に収め、
    // サイドバーの上に乗せないため、body ではなくこのページのコンテナへ描画する。
    // ref ではなく state で保持するのは、ref 代入では再レンダリングが起きず、
    // 初回描画時に container が null（＝ body へフォールバック）のままになるため。
    const [drawerContainer, setDrawerContainer] = useState<HTMLDivElement | null>(null);

    // ドロワーを開いた起点の要素。閉じたときにここへフォーカスを戻す。
    // Sheet は SheetTrigger 経由で開いていないため、Radix は復帰先を知らない（放置するとフォーカスが body に落ち、
    // キーボード操作では一覧の先頭から辿り直しになる）。開いた瞬間の activeElement を自前で覚えておく。
    const openerRef = useRef<HTMLElement | null>(null);

    // 固定領域（ページヘッダー＋対象人材サマリー）。フォーカス復帰時に「起点カードがこの帯の裏に
    // 隠れていないか」を実測するために参照する。高さは表示条件によって変わるため定数化しない。
    const stickyHeaderRef = useRef<HTMLDivElement | null>(null);

    /**
     * ドロワーを閉じたときのフォーカス復帰。
     *
     * 固定領域を sticky 化した（issue #82）ことで、スクロールポートの上端が固定領域の「裏」になった。
     * focus() 既定のスクロール（最寄りの端に寄せる）は、対象が上方向にあるとき上端に寄せるため、
     * 起点カードがヘッダーの裏に入りフォーカスリングが見えなくなる。
     * そこで focus 自体のスクロールは抑止し、実際に隠れている / 画面外のときだけ中央へ寄せる
     * （登録・編集画面の scrollIntoView({ block: 'center' }) と同じ考え方）。
     * 見えているときは動かさない：ドロワーを閉じるたびに一覧がスクロールする方が体験として悪いため。
     */
    const restoreFocusAfterClose = () => {
        const opener = openerRef.current;
        // 起点が残っていればそこへ、消えていれば一覧コンテナへ戻す。
        const target = opener?.isConnected ? opener : drawerContainer;
        if (!target) return;

        target.focus({ preventScroll: true });

        // 一覧コンテナ（シェル）自体は全高なのでスクロール調整は不要。
        if (target === drawerContainer) return;

        const headerBottom = stickyHeaderRef.current?.getBoundingClientRect().bottom ?? 0;
        const rect = target.getBoundingClientRect();
        if (rect.top < headerBottom || rect.bottom > window.innerHeight) {
            target.scrollIntoView({ block: 'center' });
        }
    };

    const openDrawer = (index: number) => {
        openerRef.current = document.activeElement as HTMLElement | null;
        setSelected(index);
    };

    // 再マッチング（#52）：AI を再実行して一覧を最新化する明示的オプトイン導線。
    // 自動再実行は行わない（コスト・並び替わり・成功直後の空状態化を避けるため）方針は維持し、
    // ユーザーが押したときだけ回す。リロード/ブラウザバックしか最新化手段が無い状態を解消する。
    const [isRerunning, setIsRerunning] = useState(false);

    // マッチングは読み取り専用（DB保存なし）のため、途中キャンセルは安全（副作用が残らない）。
    // Inertia visit の cancel トークンを保持し、オーバーレイのキャンセルボタンから中断する（人材詳細と同方式）。
    const rerunCancel = useRef<(() => void) | null>(null);

    // キャンセル時に開いていたドロワーへ戻すための退避先（キャンセル＝何も起きなかったことにする）。
    // 下記のとおり現状は常に null が入る。
    const selectedBeforeRerun = useRef<number | null>(null);

    const handleRerun = () => {
        // 再取得で並びが変わると、index で選択しているドロワーが別案件の内容に化ける。
        // そのため実行前に必ず閉じ、ユーザーが見ている対象と中身がズレた状態を作らない。
        //
        // ⚠️ 現状この2行は実質デッドコード：ドロワー（Sheet）は modal なので、開いている間は
        // Radix が body の pointer-events を無効化し、かつフォーカストラップが効くため、
        // 再マッチングボタンにはマウスでもキーボードでも到達できない（＝ selected は常に null）。
        // それでも残すのは、Sheet を modal={false} に変えた瞬間に上記のズレが無言で復活するため
        // （防御的プログラミング。到達不能でもコストは実質ゼロ）。
        selectedBeforeRerun.current = selected;
        setSelected(null);

        // サーバーに到達できない通信断は onError にも flash にも乗らないが、レイアウトが
        // useConnectionErrorToast() で exception を購読してトーストを出すため（#84）、ここでは購読しない
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
    // ただし handleRerun のとおり Sheet が modal である限り退避先は常に null＝実際には復元は起きない。
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
        // 地色（bg-muted/30）は他の参照系画面と同じく <main> に載せる。この画面のスクロール箱は
        // h-full＝シェルの h-screen と同高なので見た目は同じだが、地色を <main> に預けておけば
        // 「h-screen が <main> の高さと一致する」前提が崩れたときにも白背景が露出しない。
        // ※ bg-muted/30 は半透明のため、スクロール箱側と二重に指定しないこと（濃度が変わる）。
        <AuthenticatedLayout mainClassName="bg-muted/30">
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

                位置決めシェル（この div）とスクロール箱（内側の div）を分けているのが要点。
                ドロワーはこのシェルへ Portal され absolute inset-y-0 で配置されるため、シェル自身を
                スクロール箱にすると包含ブロックがスクロール全高になり、ドロワーがビューポート高ではなく
                結果一覧の全長まで伸びたうえスクロールに追従して流れ去る。シェルは h-screen＋overflow-hidden の
                ままにして、スクロールは内側の箱に閉じ込める。

                ヘッダ・サマリーをスクロール箱の外に置くと、スクロールバー幅（約15px）を結果一覧だけが
                内側で負担して左右端が一致しないため、同じ箱の中に入れて sticky で留める（issue #82）。 */}
            <div
                ref={setDrawerContainer}
                // ドロワーを閉じたときの復帰先（起点のカードが消えている場合）。マウスでは焦点化しない
                tabIndex={-1}
                className="relative -m-6 h-screen overflow-hidden outline-none"
            >
                {/* スクロール箱。地色は <main>（mainClassName）側で指定するためここには置かない。 */}
                <div className="h-full overflow-y-auto">
                    {/* 固定領域：ページヘッダー＋対象人材サマリー。
                        bg-white は必須。サマリー帯は bg-muted/40＝半透明で、単独では背後を通過する
                        結果カードが透ける。
                        ref はフォーカス復帰時の遮蔽判定（restoreFocusAfterClose）に使う。 */}
                    <div ref={stickyHeaderRef} className="sticky top-0 z-10 bg-white">
                        {/* ページヘッダー（WF_09：タイトル＋サブタイトル。#52 で右側に再マッチングを追加） */}
                        <div className="flex items-center justify-between border-b border-border bg-white px-10 py-4">
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
                        <div className="border-b border-border bg-muted/40 px-10 py-3">
                            {/* 上段：氏名 + ステータスバッジ（最重要属性なので氏名の直後に置き、同一性と状態をまとめて読ませる） */}
                            <div className="flex flex-wrap items-center gap-2">
                                {/* 氏名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（TruncatedText）。
                                    固定の max-w は付けず flex-1 max-w-fit で当てる。min-w-0 だけでは
                                    「折り返すか」の判定に使う基準サイズが max-content（truncate により全文1行分）
                                    のままで、氏名が親幅を超えるとステータスバッジが2行目へ落ちるため。
                                    max-w-fit は flex-1 とセット：これが無いと氏名が余白まで伸びて
                                    ステータスバッジが右端まで離れる（人材詳細・案件詳細と同じ組み方）。 */}
                                <TruncatedText
                                    as="p"
                                    text={engineer.name}
                                    className="min-w-0 max-w-fit flex-1 text-base font-bold text-foreground"
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
                                    // gap-0：駅名と「（路線）」は1つの値の続きなので、MetaItem 既定の
                                    // 隙間（gap-1）を入れない（「東京駅 （山手線）」と割れて見えるため）。
                                    className="max-w-[18rem] gap-0"
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
                    </div>

                    {/* 結果一覧（WF_09 の list-area）。地色は <main> 側の bg-muted/30。
                        左右ガターは固定領域（px-10）と揃える。 */}
                    <div className="px-10 py-4">
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
                        // フォーカスが body に落ちる。復帰先の決定と、sticky ヘッダーに隠れないための
                        // スクロール調整は restoreFocusAfterClose に集約している。
                        event.preventDefault();
                        restoreFocusAfterClose();
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
