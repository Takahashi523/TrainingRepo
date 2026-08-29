import TruncatedText from '@/Components/Common/TruncatedText';
import { SelectValue } from '@/Components/ui/select';

/**
 * TruncatedSelectValue と必ずセットで SelectTrigger の className に足すクラス。
 *
 * shadcn の SelectTrigger は既定で `[&>span]:line-clamp-1` を持ち、選択中の値を
 * -webkit-box で1行に切る（＝省略されるが全文を確認する手段が無い）。省略の責務は
 * TruncatedText に一本化したいので clamp を外し、値の span を「縮められる普通のブロック」に戻す。
 * これで内側の truncate が親幅で効き、scrollWidth による省略判定も他画面とまったく同じ条件で動く
 * （-webkit-box の中では子要素の幅が親幅で決まる保証がなく、判定が効かないおそれがある）。
 * 値が長いときにシェブロンが潰れないよう shrink-0 も明示する。
 */
export const TRUNCATED_SELECT_TRIGGER_CLASS =
    '[&>span]:line-clamp-none [&>span]:block [&>span]:min-w-0 [&>svg]:shrink-0';

/**
 * ドロップダウン（SelectContent）がトリガーより狭くならないようにするクラス。
 *
 * shadcn は Viewport に `min-w-[var(--radix-select-trigger-width)]` を付けているが、
 * Content 側の `max-w-*`（長い氏名で横に伸びるのを抑えるための指定）が上位で効くため、
 * トリガーが max-w より広いとドロップダウンだけが狭く（トリガーと不揃いに）表示される。
 * Content 自身にも同じ min-w を与える（CSS では min-width が max-width に優先する）。
 * 選択肢は省略せず折り返して全文を見せる方針のため、幅の下限をトリガーに合わせるのが自然。
 */
export const SELECT_CONTENT_MATCH_TRIGGER_CLASS = 'min-w-[var(--radix-select-trigger-width)]';

interface Props {
    /**
     * 選択中の表示ラベル。未選択のときは null を渡す。
     * 「（なし）」のようにセンチネル値（'__none__' 等）で未選択を表す Select では、
     * Radix から見ると値が入っているため、この label が null でも placeholder の文言が使われる。
     */
    label: string | null;
    /** 未選択時に出す文言（従来 SelectValue の placeholder に渡していた値） */
    placeholder?: string;
}

/**
 * 選択中の値を1行省略し、省略が起きたときだけホバーで全文を出す SelectValue。
 *
 * SelectValue に **children を渡す**のが要点。children が無いとき Radix は選択中項目の
 * SelectItemText の中身をトリガー（value node）へ portal で複製するため、SelectItem 側に
 * 状態と ref を持つコンポーネント（TruncatedText）を置くと同じ JSX から独立した2インスタンスが
 * 生まれ、計測とツールチップが二重になる。呼び出し側でラベルを解決してここへ渡すことで複製を止める。
 *
 * 未選択時の淡色はトリガーの `data-[placeholder]:text-muted-foreground` が従来どおり担うため、
 * ここでは文言だけを出す。
 */
export default function TruncatedSelectValue({ label, placeholder }: Props) {
    // 未選択（label が null）なら placeholder の文言を出す。placeholder は「選択してください」
    // 「（なし）」のような短い固定文言のため省略の対象にしない（TruncatedText を噛ませない）。
    const text = label ?? placeholder ?? '';

    return (
        // placeholder は SelectValue にも渡す。Radix は value が空文字／未定義のとき
        // children を無視して placeholder prop を描画するため、ここを省くと
        // 未選択時に何も表示されなくなる（EngineerForm の担当営業が該当）。
        // children は undefined にしない（undefined だと Radix が SelectItemText の複製を再開する）。
        <SelectValue placeholder={placeholder}>
            {text ? (
                // block：truncate（overflow:hidden）は inline 非置換要素には効かない。
                // 他の利用箇所は親が flex で子がブロック化されるため素の span で足りるが、
                // SelectValue の中身は block の親に置かれる普通の inline になるため、
                // 明示的にブロック化しないと氏名がトリガーからはみ出し、
                // clientWidth / scrollWidth も 0 になって省略判定（ツールチップ）も動かない。
                //
                // pointer-events-auto：Radix は SelectValue の span に pointerEvents:'none' を
                // インラインで当てるため、そのままではホバーを検知できずツールチップが出ない。
                // 子で明示的に戻す（クリックはトリガーへバブリングするので開閉は従来どおり）。
                <TruncatedText text={text} className="pointer-events-auto block" />
            ) : (
                ''
            )}
        </SelectValue>
    );
}
