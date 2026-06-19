import { Input } from "@/Components/ui/input";
import { Skill } from "@/types/engineer";
import { Plus, X } from "lucide-react";

interface Props {
    skills: Skill[];
    onChange: (skills: Skill[]) => void;
    error?: string;
}

export default function SkillInput({ skills, onChange, error }: Props) {
    const add = () => {
        onChange([...skills, { label: "", detail: "" }]);
    };

    const remove = (index: number) => {
        onChange(skills.filter((_, i) => i !== index));
    };

    const update = (index: number, field: keyof Skill, value: string) => {
        onChange(
            skills.map((s, i) => (i === index ? { ...s, [field]: value } : s)),
        );
    };

    return (
        <div className="w-full space-y-2">
            {skills.map((skill, index) => (
                <div
                    key={index}
                    className="relative rounded border border-border bg-muted/30 p-3"
                >
                    <button
                        type="button"
                        onClick={() => remove(index)}
                        className="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded border border-border bg-white text-muted-foreground hover:border-destructive hover:text-destructive"
                    >
                        <X className="h-3 w-3" />
                    </button>

                    <div className="mb-2 flex items-center gap-2">
                        <input
                            type="text"
                            value={skill.label}
                            maxLength={15}
                            placeholder="ラベル（最大15文字）"
                            onChange={(e) =>
                                update(index, "label", e.target.value)
                            }
                            className="h-7 w-44 shrink-0 rounded-full border border-muted-foreground/60 bg-muted px-3 text-xs font-bold placeholder:font-normal placeholder:text-muted-foreground/60 focus:border-foreground focus:outline-none"
                        />
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {skill.label.length} / 15
                        </span>
                    </div>

                    <Input
                        type="text"
                        value={skill.detail}
                        maxLength={500}
                        placeholder="詳細・条件（任意）"
                        onChange={(e) =>
                            update(index, "detail", e.target.value)
                        }
                        className="h-8 text-xs"
                    />
                </div>
            ))}

            <button
                type="button"
                onClick={add}
                className="inline-flex h-7 items-center gap-1 rounded border border-dashed border-muted-foreground/40 bg-muted/20 px-3 text-xs text-muted-foreground hover:border-muted-foreground hover:text-foreground"
            >
                <Plus className="h-3 w-3" />
                スキルを追加
            </button>

            {error && <p className="text-xs text-destructive">{error}</p>}

            <p className="text-xs text-muted-foreground">
                ラベルは一覧・マッチング結果にタグ表示されます。詳細はAIマッチング判定の参考情報として使用します
            </p>
        </div>
    );
}
