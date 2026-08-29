import TruncatedText from '@/Components/Common/TruncatedText';
import { SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';

/**
 * トリガー側の既定を打ち消すクラス。**必ず本部品の内側だけで使う**（export しない）。
 *
 * shadcn の SelectTrigger は既定で `[&>span]:line-clamp-1` を持ち、選択中の値を
 * -webkit-box で1行に切る（＝省略されるが全文を確認する手段が無い）。省略の責務は
 * TruncatedText に一本化したいので clamp を外し、値の span を「縮められる普通のブロック」に戻す。
 * これで内側の truncate が親幅で効き、scrollWidth による省略判定も他画面とまったく同じ条件で動く
 * （-webkit-box の中では子要素の幅が親幅で決まる保証がなく、判定が効かないおそれがある）。
 * 値が長いときにシェブロンが潰れないよう shrink-0 も明示する。
 */
const TRIGGER_RESET_CLASS =
    '[&>span]:line-clamp-none [&>span]:block [&>span]:min-w-0 [&>svg]:shrink-0';

/**
 * ドロップダウン（SelectContent）がトリガーより狭くならないようにするクラス。
 *
 * shadcn は Viewport に `min-w-[var(--radix-select-trigger-width)]` を付けているが、
 * Content 側の `max-w-*`（長い値で横に伸びるのを抑えるための指定）が上位で効くため、
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
     *
     * 値に対応する選択肢が見つからない場合は null ではなく `ID:123` のような
     * 識別可能な文字列を渡すこと。null を渡すと未選択の文言が出て、
     * 「画面は未選択・送信値は id」という表示と実体の食い違いになる。
     */
    label: string | null;
    /** 未選択時に出す文言（従来 SelectValue の placeholder に渡していた値）。短い固定文言のみを想定する */
    placeholder?: string;
    /** トリガーの見た目（幅・高さ・エラー枠など）。既定の打ち消しクラスより後に適用される */
    className?: string;
}

/**
 * 選択中の値を1行省略し、省略が起きたときだけホバーで全文を出す SelectTrigger。
 *
 * **トリガーと値をこの1部品に閉じている**のが要点。既定の打ち消しクラスと `SelectValue` の
 * children 化はセットで初めて成立し、片方だけだと
 * 「clamp が残って省略判定が効かない（ツールチップが出ない）」か
 * 「overflow が開いたまま値がトリガーからはみ出す」のどちらかになる。
 * 呼び出し側に2つを正しく並べる責任を負わせない。
 *
 * SelectValue に children を渡すのは、Radix が選択中項目の SelectItemText の中身を
 * トリガーへ portal で複製するのを止めるため。複製されると、状態と ref を持つ
 * TruncatedText が同じ JSX から2インスタンス生まれ、計測とツールチップが二重になる。
 *
 * 未選択時の淡色はトリガーの `data-[placeholder]:text-muted-foreground` が従来どおり担う。
 */
export default function TruncatedSelectTrigger({ label, placeholder, className }: Props) {
    // 未選択（label が null）なら placeholder の文言を出す。placeholder は「選択してください」
    // 「（なし）」のような短い固定文言のため省略の対象にしない（TruncatedText を噛ませない）。
    const text = label ?? placeholder ?? '';

    return (
        <SelectTrigger className={cn(TRIGGER_RESET_CLASS, className)}>
            {/* placeholder は SelectValue にも渡す。Radix は value が空文字／未定義のとき
                children を無視して placeholder prop を描画するため、ここを省くと
                未選択時に何も表示されなくなる（EngineerForm の担当営業が該当）。
                children は undefined にしない（undefined だと Radix が SelectItemText の複製を再開する）。 */}
            <SelectValue placeholder={placeholder}>
                {text ? (
                    // block：truncate（overflow:hidden）は inline 非置換要素には効かない。
                    // 他の利用箇所は親が flex で子がブロック化されるため素の span で足りるが、
                    // SelectValue の中身は block の親に置かれる普通の inline になるため、
                    // 明示的にブロック化しないと値がトリガーからはみ出し、
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
        </SelectTrigger>
    );
}
