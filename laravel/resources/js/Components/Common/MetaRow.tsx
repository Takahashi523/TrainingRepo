import { EmptyFieldKey, fieldName } from '@/lib/emptyValue';
import { cn } from '@/lib/utils';
import { LucideIcon } from 'lucide-react';
import { Children, ReactElement, isValidElement } from 'react';

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
    /**
     * 行を折り返さず1行に収める（既定は折り返す）。
     *
     * 見出し直下のサマリー行のように「1行であること」自体が体裁の一部な場所で使う。
     * flex は**基準サイズ（max-content）で折り返すかを先に決め、縮小はその後**に行うため、
     * 折り返し可のままでは長い値が隣に並ばず自分の行へ送られてしまう（幅に空きがあっても2行になる）。
     *
     * このモードでは、はみ出し分を **`data-shrinkable` を付けた項目だけ**が引き受ける。
     * 全項目を縮ませると、短い固定長の項目（年齢・稼働可能時期など）まで比例配分で縮み、
     * 枠からテキストがはみ出して隣と重なるため。
     */
    nowrap?: boolean;
}

/** 縮んでよい項目か（`data-shrinkable` を付けた項目＝可変長で TruncatedText を持つもの）。 */
function isShrinkable(item: ReturnType<typeof Children.toArray>[number]): boolean {
    if (!isValidElement(item)) return false;
    const props = (item as ReactElement<{ 'data-shrinkable'?: boolean }>).props;
    return props['data-shrinkable'] === true;
}

export default function MetaRow({ children, className, nowrap = false }: MetaRowProps) {
    // null / false を挟んだ条件描画（{cond && <MetaItem/>}）でも区切りがずれないよう、
    // 実際に描画されるものだけを対象にする。
    // Children.toArray は null / undefined / boolean を除外して平坦化するため、これだけで足りる。
    // isValidElement で追加フィルタすると文字列・数値の子まで無言で消え、
    // <MetaRow>担当：{name}</MetaRow> のような書き方が警告なしに空になるため使わない。
    const items = Children.toArray(children);

    return (
        <div
            className={cn(
                'mt-1 flex items-baseline gap-x-1.5 text-[11px] text-muted-foreground',
                nowrap ? 'flex-nowrap' : 'flex-wrap',
                className,
            )}
        >
            {items.map((item, index) => (
                <span
                    key={index}
                    className={cn(
                        'inline-flex items-baseline gap-1',
                        // 折り返し可の行は従来どおり全項目が縮める（はみ出す前に折り返すため実害がない）。
                        !nowrap && 'min-w-0 shrink',
                        // 1行固定の行では、縮むのは data-shrinkable を付けた項目だけ。
                        // さらに flex-1（基準サイズ0）で取り分を等分にし、max-w-fit で
                        // 内容以上には伸ばさない（使わない分は他の可変長項目に返る）。
                        // 既定の縮小は基準サイズに比例するため、これが無いと文字数の多い項目が
                        // 幅を多く取り、短い項目だけが数文字まで潰れる。
                        nowrap && (isShrinkable(item) ? 'min-w-0 max-w-fit flex-1' : 'shrink-0'),
                    )}
                >
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
    /**
     * `MetaRow nowrap` の行で、はみ出し分をこの項目が引き受ける（＝1行に収めるために縮む）。
     * 可変長で `TruncatedText` を持つ項目に付ける。短い固定長の項目には付けない
     * （付けると比例配分で縮み、テキストが枠からはみ出して隣と重なる）。
     */
    'data-shrinkable'?: boolean;
}

/**
 * メタ行の1項目。`field` の項目名を sr-only で前置する。
 * 値そのもの（`children`）は呼び出し側が組み立てる（欠損時は emptyText(key, true) を渡す）。
 */
export function MetaItem({
    field,
    valueHasFieldName = false,
    icon: Icon,
    children,
    className,
    'data-shrinkable': shrinkable,
}: MetaItemProps) {
    // 欠損時の値は「クライアント未設定」のように項目名を含む（型2 の規則）。
    // その場合に sr-only を足すと「クライアント：クライアント未設定」と二重に読まれるため、
    // 呼び出し側が valueHasFieldName を立てた項目は sr-only を出さない
    // （項目名は必ず1回だけ、という大原則を保つ）。
    const label = fieldName(field);

    return (
        // data-shrinkable は MetaRow が「どの項目が縮むか」を判定するために読む
        // （DOM にも残して、崩れたときにどの項目が縮む設定かを開発者ツールで追えるようにする）。
        <span
            data-shrinkable={shrinkable}
            className={cn('inline-flex min-w-0 items-baseline gap-1', className)}
        >
            {!valueHasFieldName && <span className="sr-only">{label}：</span>}
            {Icon && <Icon aria-hidden="true" className="h-3 w-3 shrink-0 self-center" />}
            {children}
        </span>
    );
}
