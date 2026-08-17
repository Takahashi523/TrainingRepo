import { cn } from '@/lib/utils';
import { Checkbox } from '@/Components/ui/checkbox';
import { Label } from '@/Components/ui/label';
// 工程の形は人材・案件・マッチング共通のため、共通部品はドメイン型（@/types/engineer）ではなく
// 共有の @/types を参照する。
import { Phase } from '@/types';

/** T のうち値が boolean のプロパティ名だけを取り出す（フラグ以外のキーを渡せないようにする）。 */
type BooleanKeys<T> = { [K in keyof T]: T[K] extends boolean ? K : never }[keyof T];

/**
 * phases 配列（key/name + 真偽フラグ）を ProcessCheckboxGroup の props（phases + values）へ変換する
 * 共通アダプタ。案件（is_target）・人材（has_experience）などフラグのキー名だけが異なる同型の変換を
 * 一元化する（DRY）。呼び出し側は `const { phaseList, phaseValues } = buildProcessPhaseProps(xs, 'is_target')`。
 *
 * flagKey は `keyof T` ではなく boolean 値のキーに限定する。`'name'` のような文字列キーを渡せてしまうと
 * `Boolean(p['name'])` が常に true となり「全工程がチェック済みに見える」誤りが型で防げないため。
 */
export function buildProcessPhaseProps<T extends Phase>(
    phases: T[],
    flagKey: BooleanKeys<T>,
): { phaseList: Phase[]; phaseValues: Record<string, boolean> } {
    return {
        phaseList: phases.map(({ key, name }) => ({ key, name })),
        phaseValues: Object.fromEntries(phases.map((p) => [p.key, Boolean(p[flagKey])])),
    };
}

interface Props {
    phases: Phase[];
    values: Record<string, boolean>;
    onChange?: (key: string, checked: boolean) => void;
    readOnly?: boolean;
    className?: string;
    /**
     * ラベル文字のスタイルを外側から上書きする。未指定時は既定（黒＝foreground）。
     * 例：カード・サマリー等でメタ表示と基調を揃えたいときに `text-muted-foreground` を渡す。
     */
    labelClassName?: string;
    /**
     * チェックボックス id / label htmlFor の接頭辞。
     * 同一ページで複数インスタンスを描画する場合（例：マッチング結果カードを複数枚）に
     * id 重複を避けるため呼び出し側で一意な値を渡す。未指定時は従来どおり `phase-<key>`。
     */
    idPrefix?: string;
}

export default function ProcessCheckboxGroup({ phases, values, onChange, readOnly = false, className, labelClassName, idPrefix = '' }: Props) {
    return (
        <div className={cn('flex flex-wrap gap-x-6 gap-y-3', className)}>
            {phases.map((phase) => {
                const fieldId = `${idPrefix}phase-${phase.key}`;
                const checked = !!values[phase.key];

                return (
                    <div
                        key={phase.key}
                        // 読み取り表示（詳細・一覧・マッチング）では pointer-events-none で無効化する
                        // （disabled は not-allowed カーソルが付くため使わない。カーソルは default のまま）。
                        // さらに未チェック（経験なし）の項目のみ opacity-50 でグレーアウトし、
                        // チェック済み（経験あり）を際立たせる。フル不透明だと編集できると誤解されるのも防ぐ。
                        className={cn(
                            'flex items-center gap-2',
                            readOnly && 'pointer-events-none',
                            readOnly && !checked && 'opacity-50',
                        )}
                    >
                        <Checkbox
                            id={fieldId}
                            checked={checked}
                            // readOnly ではキーボードの Tab 順からも外す
                            tabIndex={readOnly ? -1 : undefined}
                            onCheckedChange={
                                !readOnly && onChange
                                    ? (checked) => onChange(phase.key, !!checked)
                                    : undefined
                            }
                        />
                        <Label
                            htmlFor={fieldId}
                            // ラベル色は既定で黒（foreground）。カード・サマリー等でメタ表示と基調を揃えたい場合は
                            // 呼び出し側が labelClassName（例：text-muted-foreground）で上書きする。
                            // チェックの有無はラベル色ではなく、チェックボックスの塗り＋未チェックの opacity-50 で表す。
                            className={cn(
                                'text-sm font-normal',
                                readOnly ? 'cursor-default' : 'cursor-pointer',
                                labelClassName,
                            )}
                        >
                            {phase.name}
                        </Label>
                    </div>
                );
            })}
        </div>
    );
}
