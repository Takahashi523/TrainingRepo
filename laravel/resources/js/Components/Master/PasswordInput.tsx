import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';

interface Props {
    id?: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    autoComplete?: string;
    maxLength?: number;
    error?: boolean;
}

/**
 * 表示トグル付きパスワード入力。
 *
 * 管理者が初期パスワードを設定し本人へ通知する運用（QA #20）のため、
 * 入力値を目視確認できるよう目のアイコンで表示/非表示を切り替える。
 * 既存に共通部品がないためマスタ管理内に新規作成（将来共通化の余地あり）。
 */
export default function PasswordInput({
    id,
    value,
    onChange,
    placeholder,
    autoComplete,
    maxLength,
    error,
}: Props) {
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <Input
                id={id}
                type={visible ? 'text' : 'password'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                autoComplete={autoComplete}
                maxLength={maxLength}
                className={cn('pr-10', error && 'border-destructive')}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                onClick={() => setVisible((v) => !v)}
                className="absolute right-0 top-0 h-10 w-10 text-muted-foreground hover:bg-transparent hover:text-foreground [&_svg]:size-4"
                aria-label={visible ? 'パスワードを非表示' : 'パスワードを表示'}
                title={visible ? 'パスワードを非表示' : 'パスワードを表示'}
                tabIndex={-1}
            >
                {visible ? <EyeOff /> : <Eye />}
            </Button>
        </div>
    );
}
