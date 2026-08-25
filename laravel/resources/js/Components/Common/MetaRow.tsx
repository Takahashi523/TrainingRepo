import { EmptyFieldKey, fieldName } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';
import { Children } from 'react';

/**
 * 見出し直下の圧縮メタ行（表示規約の「型2」）。
 *
 * 項目名（ラベル語）は視覚的には出さず、値の書式（単位・記号）で自己識別させる。
 * ただし**項目名を捨てるわけではなく** `sr-only` で支援技術に渡す（MetaItem）。
 * これにより「見た目は統一・情報は落とさない」を両立する（docs/UI表示規約.md）。
 *
 * 区切り「｜」は装飾のため aria-hidden とし、各項目の先頭側に置く。
 * 値と同じ折返し単位に入れることで、行頭に「｜」だけが残るのを防ぐ。
 */
interface MetaRowProps {
    children: React.ReactNode;
    className?: string;
}

export default function MetaRow({ children, className }: MetaRowProps) {
    // null / false を挟んだ条件描画（{cond && <MetaItem/>}）でも区切りがずれないよう、
    // 実際に描画されるものだけを対象にする。
    // Children.toArray は null / undefined / boolean を除外して平坦化するため、これだけで足りる。
    // isValidElement で追加フィルタすると文字列・数値の子まで無言で消え、
    // <MetaRow>担当：{name}</MetaRow> のような書き方が警告なしに空になるため使わない。
    const items = Children.toArray(children);

    return (
        <div
            className={cn(
                'mt-1 flex flex-wrap items-baseline gap-x-1.5 text-[11px] text-muted-foreground',
                className,
            )}
        >
            {items.map((item, index) => (
                <span key={index} className="inline-flex min-w-0 items-baseline gap-1">
                    {index > 0 && (
                        <span aria-hidden="true" className="shrink-0">
                            ｜
                        </span>
                    )}
                    {item}
                </span>
            ))}
        </div>
    );
}

interface MetaItemProps {
    /** 支援技術に読ませる項目名。視覚的には表示されない（型2 はラベル語を出さないため） */
    field: EmptyFieldKey;
    /**
     * 値側に項目名が含まれるか。欠損トークンを項目名入り（`emptyText(key, true)` / `withFieldName`）で
     * 出す分岐で true を渡す。true のとき sr-only の項目名を省き、二重読みを防ぐ。
     *
     * 値の中身から推測しないのは意図的。`children` は `<Rate>` / `<TruncatedText>` / 配列など
     * 文字列でないことが多く推測が効かないうえ、実データが項目名で始まる場合
     * （顧客名「クライアントサービス株式会社」など）に必要なラベルを誤って消すため。
     * `Rate` / `EmptyValue` の `withFieldName` と同じく、呼び出し側が明示する。
     */
    valueHasFieldName?: boolean;
    /** 視覚的なスキャン補助のアイコン。読み上げ対象外（項目名は sr-only が担う） */
    icon?: LucideIcon;
    children: React.ReactNode;
    className?: string;
}

/**
 * メタ行の1項目。`field` の項目名を sr-only で前置する。
 * 値そのもの（`children`）は呼び出し側が組み立てる（欠損時は emptyText(key, true) を渡す）。
 */
export function MetaItem({ field, valueHasFieldName = false, icon: Icon, children, className }: MetaItemProps) {
    // 欠損時の値は「クライアント未設定」のように項目名を含む（型2 の規則）。
    // その場合に sr-only を足すと「クライアント：クライアント未設定」と二重に読まれるため、
    // 呼び出し側が valueHasFieldName を立てた項目は sr-only を出さない
    // （項目名は必ず1回だけ、という大原則を保つ）。
    const label = fieldName(field);

    return (
        <span className={cn('inline-flex min-w-0 items-baseline gap-1', className)}>
            {!valueHasFieldName && <span className="sr-only">{label}：</span>}
            {Icon && <Icon aria-hidden="true" className="h-3 w-3 shrink-0 self-center" />}
            {children}
        </span>
    );
}
