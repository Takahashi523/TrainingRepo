import CollapsibleTagRow from '@/Components/Common/CollapsibleTagRow';
import ProcessCheckboxGroup from '@/Components/Engineers/ProcessCheckboxGroup';
import SkillTag from '@/Components/Common/SkillTag';
import TruncatedText from '@/Components/Common/TruncatedText';
import MatchCard from '@/Components/Matching/MatchCard';
import MatchDrawer from '@/Components/Matching/MatchDrawer';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { MatchingShowPageProps } from '@/types/matching';
import { Head } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { useState } from 'react';

type Props = PageProps<MatchingShowPageProps>;

// ステータスバッジの配色語彙は人材一覧カード（#32 StatusBadge）と揃える。
const STATUS_STYLES: Record<string, { label: string; badge: string }> = {
    proposable: { label: '提案可', badge: 'border-green-600 text-green-700 bg-green-50' },
    interviewing: { label: '面談中', badge: 'border-amber-500 text-amber-700 bg-amber-50' },
    not_proposable: { label: '提案不可', badge: 'border-gray-400 text-gray-600 bg-gray-50' },
};

export default function Show({ engineer, results }: Props) {
    // 選択中カードの index（ドロワー開閉）。null で閉じている。
    const [selected, setSelected] = useState<number | null>(null);

    const current = selected != null ? results[selected] : null;

    // 工程経験を人材登録・一覧と同じ ProcessCheckboxGroup で表示する（readOnly）。
    const phaseList = engineer.phases.map(({ key, name }) => ({ key, name }));
    const phaseValues: Record<string, boolean> = Object.fromEntries(
        engineer.phases.map((p) => [p.key, p.has_experience] as [string, boolean]),
    );


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
                        <span
                            className={`inline-block shrink-0 rounded-full border px-3 py-0.5 text-xs font-bold ${
                                STATUS_STYLES[engineer.status]?.badge ?? 'border-gray-400 text-gray-600 bg-gray-50'
                            }`}
                        >
                            {STATUS_STYLES[engineer.status]?.label ?? engineer.status}
                        </span>
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
                                <SkillTag key={i} label={s.label ?? ''} className="text-muted-foreground" />
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
                        <div className="flex flex-col items-center justify-center rounded-md border border-dashed border-border py-16 text-center">
                            <SearchX className="mb-2 h-8 w-8 text-muted-foreground" />
                            <p className="text-sm font-semibold text-foreground">マッチする案件がありませんでした</p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                条件に合う募集中の案件が見つからないか、マッチングを実行できませんでした。
                            </p>
                        </div>
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
