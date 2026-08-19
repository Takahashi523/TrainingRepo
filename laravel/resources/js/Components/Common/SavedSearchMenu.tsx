import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { ConditionValue, SavedSearchItem } from '@/types/savedSearch';
import { Filter } from 'lucide-react';

interface Props<TConditions extends Record<string, ConditionValue>> {
    savedSearches: SavedSearchItem<TConditions>[];
    onApply: (conditions: TConditions) => void;
}

/**
 * 保存済み検索条件を呼び出すランチャー（WF_03/WF_06の「保存済み条件」に対応）。
 * 保存・削除の管理は SavedSearchSaveDialog / SavedSearchManageDialog 側の責務とし、ここでは適用のみを扱う。
 *
 * フォーム部品（Select）ではなくメニューで実装している。
 * 呼び出しの実体（適用中の絞り込み）はこのUIの外にあり、適用後の手編集でズレていくため、
 * 「選んだ値＝その場の状態」を前提とする Select とは役割が合わない。
 * メニューの各項目を「押すと適用されるアクション」として扱うことで、次の副作用が構造的に発生しない：
 *   - 適用中の条件を削除しても、トリガーは保存済み条件を参照しないので空表示で固まらない
 *   - onSelect は値の差分比較を持たないため、同じ条件を続けて選んでも毎回適用される
 *   - 適用中の内容を表示しないため、手編集による「嘘表示」が起きない（適用中の条件は絞り込みタグが正）
 *   - id で項目を識別せず conditions を直接渡すため、同一 conditions の保存が複数あっても取り違えない
 */
export default function SavedSearchMenu<
    TConditions extends Record<string, ConditionValue>,
>({ savedSearches, onApply }: Props<TConditions>) {
    return (
        <DropdownMenu>
            {/* トリガーは隣の「☰ 条件管理」と同じボタン意匠に揃える。
                セレクト風（固定幅・淡色プレースホルダー・シェブロン）にすると「値を保持する部品」に見えてしまい、
                実際には値を持たない（適用中の条件は絞り込みタグが正）という本部品の性質と食い違うため。 */}
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-8 gap-1 bg-white text-[11px] [&_svg]:size-3.5"
                >
                    {/* アイコンの大きさは Button 側の [&_svg]:size-* で決まる（svg 自身の h-/w- は
                        親の指定に負けるため効かない）。ファネルは面積が大きく見えるので既定の 4 から
                        3.5 に落とし、文字とのバランスを取る。 */}
                    <Filter />
                    保存済み条件を選択
                </Button>
            </DropdownMenuTrigger>
            {/* 条件名は最長でも100文字だが、省略表示（…）はしない。
                ここは呼び出しのためだけの一時的なポップオーバーで、他の操作要素を巻き込まないため、
                多少幅が広がったり折り返されたりしても実害がない（モーダル側とは異なる判断）。
                右クラスタに置かれるトリガーのため align="end" とし、幅が広がる方向を画面内側（左）にする。 */}
            <DropdownMenuContent align="end" className="min-w-[200px]">
                {savedSearches.length === 0 ? (
                    // 素のテキストではなく disabled 項目にする。メニューの一員として支援技術に伝わり、
                    // かつキーボード移動・選択の対象にならないので誤って適用されることがない。
                    <DropdownMenuItem disabled>
                        保存された検索条件はありません
                    </DropdownMenuItem>
                ) : (
                    savedSearches.map((item) => (
                        <DropdownMenuItem
                            key={item.id}
                            onSelect={() => onApply(item.conditions)}
                        >
                            {item.name}
                        </DropdownMenuItem>
                    ))
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
