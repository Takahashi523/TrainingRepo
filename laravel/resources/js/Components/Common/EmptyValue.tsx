import { EmptyFieldKey, emptyText } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';

/**
 * 欠損値トークン（「未設定」「未定」「未割当」「未算出」）の表示。
 *
 * 語彙（emptyValue.ts）だけを共通化していたときは、呼び出し側が muted で包む箇所と
 * 素のテキストで出す箇所に分かれ、同じ「未設定」が濃色・淡色で混在していた。
 * **値が無いことは常に控えめに見せる**（実値と同じ濃さだと値が入っているように読める）ため、
 * 色をトークン側に閉じ込める。
 *
 * 文字列が必要な文脈（TruncatedText の text、テンプレートリテラル、行全体が muted の圧縮メタ行）は
 * `emptyText()` をそのまま使ってよい。
 */
interface Props {
    field: EmptyFieldKey;
    /** 項目名を含めるか（ラベルを持たない圧縮メタ行＝型2 で true） */
    withFieldName?: boolean;
    className?: string;
}

export default function EmptyValue({ field, withFieldName = false, className }: Props) {
    return (
        <span className={cn('text-muted-foreground', className)}>
            {emptyText(field, withFieldName)}
        </span>
    );
}
