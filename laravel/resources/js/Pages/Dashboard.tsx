import TruncatedText from '@/Components/Common/TruncatedText';
import { Card, CardContent } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { cn } from '@/lib/utils';
import { DashboardProps } from '@/types/dashboard';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({
    kpi,
    pipeline_summary,
    upcoming_actions,
}: DashboardProps) {
    return (
        <AuthenticatedLayout>
            <Head title="ダッシュボード" />

            {/*
             * 他画面（人材一覧・案件一覧・進捗管理）と同じヘッダ構造に統一する。
             * -m-6 で <main> の p-6 を打ち消し、固定ヘッダ＋本文スクロールの全高レイアウトにする。
             * 集計軸「メイン・サブ担当含む」はサブタイトルに1回だけ書き、各カードでの重複記載はしない。
             */}
            <div className="-m-6 flex h-screen flex-col overflow-hidden">
                <div className="shrink-0 border-b border-border bg-white px-10 py-4">
                    <h1 className="text-lg font-bold text-foreground">
                        ダッシュボード
                    </h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        メイン・サブで担当する人材・案件・パイプラインの概況を確認します
                    </p>
                </div>

                <div className="flex-1 overflow-y-auto px-10 py-6 bg-muted/30">
                    <div className="mx-auto max-w-7xl space-y-5">
                        {/* ① KPI サマリーバー */}
                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <KpiCard
                            label="提案可能人材"
                            sublabel="ステータス『提案可』の担当人数"
                            count={kpi.proposable_engineer_count}
                            unit="名"
                            total={kpi.proposable_engineer_count_total}
                            totalUnit="名"
                        />
                        <KpiCard
                            label="稼働中案件"
                            sublabel="ステータス『募集中』の担当案件数"
                            count={kpi.open_project_count}
                            unit="件"
                            total={kpi.open_project_count_total}
                            totalUnit="件"
                        />
                        <KpiCard
                            label="進行中カード総数"
                            sublabel="担当パイプラインの進行中カード件数"
                            count={kpi.active_pipeline_count}
                            unit="件"
                        />
                    </section>

                    {/* ②③ 横並び（WF_02：パイプライン進捗 6 : 近日アクション 4） */}
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                        {/* ② パイプライン進捗サマリー */}
                        <Card className="min-w-0 lg:flex-[6]">
                            <CardContent className="p-6">
                                <h3 className="mb-4 text-base font-semibold text-gray-800">
                                    パイプライン進捗サマリー
                                </h3>

                                <table className="w-full table-fixed border-collapse text-xs">
                                    <thead>
                                        <tr className="border-b border-gray-200 text-xs font-bold text-gray-500">
                                            <th className="py-1.5 pr-2 text-left">
                                                ステータス
                                            </th>
                                            <th className="w-14 py-1.5 pr-2 text-right">
                                                件数
                                            </th>
                                            <th className="w-40 py-1.5 text-right">
                                                割合
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pipeline_summary.map((row) => (
                                            <tr
                                                key={row.status}
                                                className="border-b border-gray-100 last:border-b-0"
                                            >
                                                <td
                                                    className={cn(
                                                        'truncate py-1.5 pr-2',
                                                        row.count === 0
                                                            ? 'text-gray-400'
                                                            : 'text-gray-700',
                                                    )}
                                                >
                                                    {row.status_label}
                                                </td>
                                                <td
                                                    className={cn(
                                                        'py-1.5 pr-2 text-right tabular-nums',
                                                        row.count > 0
                                                            ? 'font-bold text-gray-900'
                                                            : 'text-gray-400',
                                                    )}
                                                >
                                                    {row.count}
                                                </td>
                                                <td className="py-1.5">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <div className="h-1.5 w-20 overflow-hidden rounded-sm bg-gray-100">
                                                            <div
                                                                className="h-full rounded-sm bg-primary"
                                                                style={{
                                                                    width: `${row.percentage}%`,
                                                                }}
                                                            />
                                                        </div>
                                                        <span
                                                            className={cn(
                                                                'w-8 shrink-0 text-right text-xs tabular-nums',
                                                                row.count > 0
                                                                    ? 'font-medium text-gray-600'
                                                                    : 'text-gray-400',
                                                            )}
                                                        >
                                                            {row.percentage}%
                                                        </span>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>

                        {/* ③ 近日アクション予定 */}
                        <Card className="min-w-0 lg:flex-[4]">
                            <CardContent className="p-6">
                                <div className="mb-1 flex flex-wrap items-center justify-between gap-2">
                                    <h3 className="text-base font-semibold text-gray-800">
                                        近日アクション予定
                                    </h3>
                                    <Link
                                        href="/pipelines"
                                        className="whitespace-nowrap text-xs font-medium text-primary hover:underline"
                                    >
                                        進捗管理を見る →
                                    </Link>
                                </div>
                                {/* D8: 「近日」の定義＋上位5件のみを示すヒント文（誤解防止の UX 補助） */}
                                <p className="mb-4 text-xs text-gray-400">
                                    今日から7日以内（土日を含む）と期限を過ぎたアクションを、期日が近い順に最大5件表示しています
                                </p>

                                {upcoming_actions.length === 0 ? (
                                    <p className="py-6 text-center text-sm text-gray-400">
                                        アクション予定なし
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {upcoming_actions.map((action) => (
                                            <li
                                                key={action.id}
                                                className="flex items-center gap-3 py-2.5"
                                            >
                                                <span
                                                    className={cn(
                                                        'w-24 shrink-0 text-xs tabular-nums',
                                                        action.is_overdue
                                                            ? 'font-semibold text-red-600'
                                                            : 'text-gray-600',
                                                    )}
                                                >
                                                    {action.next_action_date}
                                                </span>
                                                <TruncatedText
                                                    className="min-w-0 flex-1 text-xs text-gray-800"
                                                    text={`${action.engineer.name} × ${action.project.name}`}
                                                />
                                                {/* ステータス名（ドットは一律で情報を持たないため省略し、テキストのみ） */}
                                                <span className="shrink-0 whitespace-nowrap text-xs text-gray-600">
                                                    {action.status_label}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

interface KpiCardProps {
    label: string;
    sublabel: string;
    count: number;
    unit: string;
    total?: number;
    totalUnit?: string;
}

// KPI カードは表示専用（リンクなし）。
function KpiCard({
    label,
    sublabel,
    count,
    unit,
    total,
    totalUnit,
}: KpiCardProps) {
    return (
        <Card>
            <CardContent className="p-5">
                <div className="text-sm font-semibold text-gray-500">
                    {label}
                </div>
                <div className="mt-2 flex items-baseline gap-1">
                    <span className="text-3xl font-bold tabular-nums text-gray-900">
                        {count}
                    </span>
                    <span className="text-sm text-gray-500">{unit}</span>
                    {total !== undefined && (
                        <span className="ml-2 text-xs text-gray-400">
                            全体 {total}
                            {totalUnit}
                        </span>
                    )}
                </div>
                <p className="mt-2 text-xs text-gray-400">{sublabel}</p>
            </CardContent>
        </Card>
    );
}
