import { EmptyFieldKey, fieldName } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';
import { Children, isValidElement } from 'react';

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
    // 実際に描画される要素だけを対象にする。
    const items = Children.toArray(children).filter((child) => isValidElement(child));

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
    /** 視覚的なスキャン補助のアイコン。読み上げ対象外（項目名は sr-only が担う） */
    icon?: LucideIcon;
    children: React.ReactNode;
    className?: string;
}

/**
 * メタ行の1項目。`field` の項目名を sr-only で前置する。
 * 値そのもの（`children`）は呼び出し側が組み立てる（欠損時は emptyText(key, true) を渡す）。
 */
export function MetaItem({ field, icon: Icon, children, className }: MetaItemProps) {
    // 欠損時の値は「クライアント未設定」のように項目名を含む（型2 の規則）。
    // その場合に sr-only を足すと「クライアント：クライアント未設定」と二重に読まれるため、
    // 値が項目名で始まるときは sr-only を出さない（項目名は必ず1回だけ、という大原則を保つ）。
    const label = fieldName(field);
    const valueHasFieldName = typeof children === 'string' && children.startsWith(label);

    return (
        <span className={cn('inline-flex min-w-0 items-baseline gap-1', className)}>
            {!valueHasFieldName && <span className="sr-only">{label}：</span>}
            {Icon && <Icon aria-hidden="true" className="h-3 w-3 shrink-0 self-center" />}
            {children}
        </span>
    );
}
