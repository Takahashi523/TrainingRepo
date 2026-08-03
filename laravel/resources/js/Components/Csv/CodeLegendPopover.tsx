import { Button } from '@/Components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/Components/ui/popover';
import { CsvResource } from '@/types/csv';
import { ChevronDown, Code2 } from 'lucide-react';

type CodeGroup = { title: string; items: [code: string, label: string][] };

/**
 * コード対応（英字コード → 日本語）の一覧。enum 系列（O-2）はこのコードのまま入力する必要がある。
 *
 * 人材・案件で使う enum 列は異なるため、リソースごとに出し分ける：
 * - 人材：ステータスのみ（商流・稼働形態 enum は無く、勤務形態は 0/1 フラグ）
 * - 案件：ステータス・商流・稼働形態
 *
 * `flagList` は 0/1 で入力する列（各リソースのフラグ列）を示す注記。
 */
const CODE_LEGEND: Record<CsvResource, { groups: CodeGroup[]; flagList: string }> = {
    engineers: {
        groups: [
            {
                title: 'ステータス',
                items: [
                    ['proposable', '提案可'],
                    ['interviewing', '面談中'],
                    ['not_proposable', '提案不可'],
                ],
            },
        ],
        flagList: '勤務形態・各工程経験・顧客折衝経験',
    },
    projects: {
        groups: [
            {
                title: 'ステータス',
                items: [
                    ['open', '募集中'],
                    ['closed', '終了'],
                    ['pending', 'ペンディング'],
                ],
            },
            {
                title: '商流',
                items: [
                    ['prime', 'プライム'],
                    ['secondary', '2次'],
                    ['tertiary', '3次'],
                    ['other', 'その他'],
                ],
            },
            {
                title: '稼働形態',
                items: [
                    ['onsite', '常駐'],
                    ['hybrid', '一部リモート可'],
                    ['remote', 'フルリモート'],
                ],
            },
        ],
        flagList: '各工程対象・顧客折衝経験要否',
    },
};

interface Props {
    /** 対象リソース。表示するコード群を人材/案件で出し分ける。 */
    resource: CsvResource;
}

/**
 * コード対応ポップオーバー（WF_11 v1.2 / 詳細設計）。
 *
 * ステータス・商流・稼働形態などの enum 列は英字コードのまま入力する必要がある（O-2）。
 * 担当者ID一覧と同じくインポートセクション右上に置き、押下時のみ開いて画面の視覚的な重さを抑える。
 * 表示するコードは resource に応じて出し分ける（無関係なコードを見せない）。
 */
export default function CodeLegendPopover({ resource }: Props) {
    const { groups, flagList } = CODE_LEGEND[resource];

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="h-7 gap-1.5 px-2.5 text-xs font-semibold [&_svg]:size-3.5"
                >
                    <Code2 />
                    コード対応
                    <ChevronDown />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64 p-0">
                <p className="border-b border-border px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    コード対応
                </p>
                <div className="max-h-72 space-y-3 overflow-y-auto px-3 py-2.5">
                    {groups.map((group) => (
                        <div key={group.title}>
                            <p className="mb-1 text-[10px] font-bold text-muted-foreground">
                                {group.title}
                            </p>
                            <ul className="space-y-0.5">
                                {group.items.map(([code, label]) => (
                                    <li
                                        key={code}
                                        className="flex items-baseline gap-2 text-xs text-sky-900"
                                    >
                                        <code className="shrink-0 rounded bg-muted px-1 py-px font-mono font-bold text-sky-900">
                                            {code}
                                        </code>
                                        <span className="min-w-0 break-all text-muted-foreground">
                                            {label}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
                <p className="border-t border-border px-3 py-2 text-[10px] text-muted-foreground">
                    この英字コードのまま入力（0/1：{flagList}）
                </p>
            </PopoverContent>
        </Popover>
    );
}
