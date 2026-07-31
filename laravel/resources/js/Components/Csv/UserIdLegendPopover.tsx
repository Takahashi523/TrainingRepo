import { Button } from '@/Components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/Components/ui/popover';
import { ChevronDown, Users } from 'lucide-react';

interface Props {
    /** 担当者一覧（GET /csv の users を流用。id→氏名。全件・admin/general 双方含む）。 */
    users: { id: number; name: string }[];
}

/**
 * 担当者ID一覧ポップオーバー（WF_11 v1.2 / 詳細設計）。
 *
 * 新規行に入力する 主担当ID / サブ担当ID を営業が確認できるよう、
 * インポートセクション右上に置く。常時展開せず押下時のみ開く（画面の視覚的な重さを抑える）。
 * データは追加エンドポイントを設けず GET /csv Props の users を流用する。
 */
export default function UserIdLegendPopover({ users }: Props) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="h-7 gap-1.5 px-2.5 text-xs font-semibold [&_svg]:size-3.5"
                >
                    <Users />
                    担当者ID一覧
                    <ChevronDown />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-56 p-0">
                <p className="border-b border-border px-3 py-2 text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                    担当者ID一覧
                </p>
                {users.length === 0 ? (
                    <p className="px-3 py-3 text-xs text-muted-foreground">
                        担当者が登録されていません。
                    </p>
                ) : (
                    <ul className="max-h-64 overflow-y-auto py-1">
                        {users.map((u) => (
                            <li
                                key={u.id}
                                className="flex items-baseline gap-2 px-3 py-1 text-xs text-foreground"
                            >
                                <span className="w-7 shrink-0 font-bold text-primary">
                                    {u.id}
                                </span>
                                <span className="min-w-0 break-all">{u.name}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="border-t border-border px-3 py-2 text-[10px] text-muted-foreground">
                    主担当ID / サブ担当ID にこの番号を入力
                </p>
            </PopoverContent>
        </Popover>
    );
}
