import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import ProcessCheckboxGroup, { buildProcessPhaseProps } from '@/Components/Engineers/ProcessCheckboxGroup';
import SkillTag from '@/Components/Common/SkillTag';
import StatusBadge from '@/Components/Common/StatusBadge';
import TruncatedText from '@/Components/Common/TruncatedText';
import MatchCard from '@/Components/Matching/MatchCard';
import MatchDrawer from '@/Components/Matching/MatchDrawer';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { MatchingEmptyReason, MatchingShowPageProps } from '@/types/matching';
import { Head } from '@inertiajs/react';
import { AlertTriangle, PackageX, SearchX } from 'lucide-react';
import { ComponentType, useState } from 'react';

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

export default function Show({ engineer, results, emptyReason }: Props) {
    // 選択中カードの index（ドロワー開閉）。null で閉じている。
    const [selected, setSelected] = useState<number | null>(null);

    const current = selected != null ? results[selected] : null;

    // 工程経験を人材登録・一覧と同じ ProcessCheckboxGroup で表示する（readOnly）。人材は has_experience。
    const { phaseList, phaseValues } = buildProcessPhaseProps(engineer.phases, 'has_experience');

    return (
        <AuthenticatedLayout>
            <Head title="マッチング結果" />

            {/* WF_09：ヘッダ・対象人材サマリーは上部固定、下の結果一覧のみスクロール。
                進捗管理・人材一覧と同じく p-6 を -m-6 で打ち消し、画面全高（h-screen）の flex カラムにする。 */}
            <div className="relative -m-6 flex h-screen flex-col overflow-hidden">
                {/* ページヘッダー（WF_09：タイトル＋サブタイトルのみ。アクションボタンは持たない） */}
                <div className="shrink-0 border-b border-border bg-white px-10 py-4">
                    <h1 className="text-lg font-bold text-foreground">マッチング結果</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">人材に合致する案件の一覧</p>
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

                    {/* 年齢・最寄駅・希望単価・勤務形態を、マッチングカードのメタと同じ「ラベルなし・｜区切りの横一列」で表示する。
                        未指定も同じ流儀でフィールド名入りトークンにし（入力済み属性＝未設定／柔軟に決まり得る条件＝未定）、カードと語彙を揃える。 */}
                    <div className="mt-1.5 flex flex-wrap items-baseline gap-x-1.5 text-[11px] text-muted-foreground">
                        <span>{engineer.age != null ? `${engineer.age}歳` : '年齢未設定'}</span>
                        {/* 最寄駅・路線名は長くなり得るため 1 行省略＋省略時のみ全文ツールチップ（max-w で幅を抑える）。 */}
                        <span className="inline-flex min-w-0 max-w-[18rem] items-baseline gap-1">
                            <span className="shrink-0">｜</span>
                            <TruncatedText
                                text={engineer.nearest_station || '最寄駅未設定'}
                                className="min-w-0 max-w-[8rem]"
                            />
                            <TruncatedText
                                text={engineer.nearest_line ? `（${engineer.nearest_line}）` : '（路線未設定）'}
                                className="min-w-0 max-w-[8rem]"
                            />
                        </span>
                        <span>｜ {engineer.available_from ? engineer.available_label : '稼働可能時期未定'}</span>
                        <span>｜ {engineer.desired_rate != null ? `${engineer.desired_rate}万円` : '希望単価未設定'}</span>
                        <span>
                            ｜{' '}
                            {engineer.work_styles.length > 0
                                ? engineer.work_styles.map((w) => w.name).join(' / ')
                                : '勤務形態未定'}
                        </span>
                    </div>

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
                            <span className="text-[11px] text-muted-foreground">スキル未設定</span>
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
                            onClose={() => setSelected(null)}
                        />
                    </div>
                </>
            )}
        </AuthenticatedLayout>
    );
}
