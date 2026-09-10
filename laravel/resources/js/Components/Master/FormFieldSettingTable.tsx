import { Switch } from '@/Components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { useToast } from '@/hooks/use-toast';
import { FormSetting, FormType } from '@/types/master';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useRef, useState } from 'react';

interface Props {
    formType: FormType;
    settings: FormSetting[];
}

/** 列幅比率（合計 100%）。項目名を狭めてトグルを近づけ、余白を詰める。 */
const COLUMN_WIDTHS = [46, 54];

/**
 * フォーム設定（必須/任意）テーブル。
 * トグル変更は即時反映（WF_12・一括保存ボタンなし）。変更のあった1件だけを
 * PUT /master/form-settings に送信し、preserveState/preserveScroll で同画面に留まる。
 * is_system_required の行は Switch を disabled にする。
 */
export default function FormFieldSettingTable({ formType, settings }: Props) {
    const { toast } = useToast();
    // 送信中のフィールドキー（当該行の Switch を一時的に無効化して連打を抑止）
    const [pendingKey, setPendingKey] = useState<string | null>(null);
    // 直近で保存に成功したフィールドキー（行内に一時的にチェックを表示）
    const [savedKey, setSavedKey] = useState<string | null>(null);
    const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const flashSaved = (key: string) => {
        if (savedTimer.current) clearTimeout(savedTimer.current);
        setSavedKey(key);
        savedTimer.current = setTimeout(() => setSavedKey(null), 1500);
    };

    const handleToggle = (setting: FormSetting, nextRequired: boolean) => {
        setPendingKey(setting.field_key);
        router.put(
            route('master.form-settings.update'),
            {
                settings: [
                    {
                        form_type: formType,
                        field_key: setting.field_key,
                        is_required: nextRequired,
                    },
                ],
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => flashSaved(setting.field_key),
                // 成功は行内フィードバックのみ。失敗はサイレントにせずトーストで通知する。
                onError: () =>
                    toast({
                        description: '設定の更新に失敗しました。時間をおいて再度お試しください。',
                        variant: 'destructive',
                        duration: 5000,
                    }),
                onFinish: () => setPendingKey(null),
            },
        );
    };

    return (
        <div className="overflow-hidden rounded-md border border-border bg-white">
            <Table className="table-fixed border-collapse text-xs">
                <colgroup>
                    {COLUMN_WIDTHS.map((w, i) => (
                        <col key={i} style={{ width: `${w}%` }} />
                    ))}
                </colgroup>
                <TableHeader>
                    <TableRow className="bg-muted hover:bg-muted">
                        <Th>項目名</Th>
                        <Th>必須 / 任意</Th>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {settings.map((setting) => (
                        <TableRow
                            key={setting.field_key}
                            className="hover:bg-muted/30"
                        >
                            <TableCell className="px-3 py-2 font-bold text-foreground">
                                {setting.field_label}
                            </TableCell>
                            <TableCell className="px-3 py-2">
                                {setting.is_system_required ? (
                                    <span className="text-[11px] font-bold text-foreground">
                                        必須（固定）
                                    </span>
                                ) : (
                                    <div className="flex items-center gap-1">
                                        <Switch
                                            checked={setting.is_required}
                                            disabled={pendingKey === setting.field_key}
                                            onCheckedChange={(next) =>
                                                handleToggle(setting, next)
                                            }
                                            aria-label={`${setting.field_label}の必須/任意`}
                                            className="-mr-2 origin-left scale-75"
                                        />
                                        <span
                                            className={
                                                setting.is_required
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {setting.is_required ? '必須' : '任意'}
                                        </span>
                                        {/* 保存成功の行内フィードバック（1.5秒表示・トーストは出さない） */}
                                        {savedKey === setting.field_key && (
                                            <span className="inline-flex items-center gap-0.5 text-[11px] font-semibold text-green-600">
                                                <Check className="h-3.5 w-3.5" />
                                                保存
                                            </span>
                                        )}
                                    </div>
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

// shadcn TableHead の既定をコンパクト表示に上書きするラッパ
function Th({ children }: { children: React.ReactNode }) {
    return (
        <TableHead className="h-auto px-3 py-2 text-left text-[11px] font-bold text-muted-foreground whitespace-nowrap">
            {children}
        </TableHead>
    );
}
