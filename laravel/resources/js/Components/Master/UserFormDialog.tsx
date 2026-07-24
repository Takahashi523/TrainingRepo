import PasswordInput from '@/Components/Master/PasswordInput';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { MasterUser, UserRole } from '@/types/master';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    /** 編集対象。null の場合は新規追加モード */
    user: MasterUser | null;
    onClose: () => void;
}

interface FormData {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    role: UserRole | '';
    [key: string]: string;
}

/** 人材登録・案件登録フォームと同じ必須バッジ（rose の小ピル） */
function RequiredBadge() {
    return (
        <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[9px] font-bold leading-tight text-rose-600">
            必須
        </span>
    );
}

/**
 * ユーザー追加・編集モーダル。
 * 新規は POST /master/users、編集は PUT /master/users/{id}。
 * 編集時にパスワードを空にすると変更なし（サーバ側で nullable）。
 */
export default function UserFormDialog({ open, user, onClose }: Props) {
    const isEdit = user !== null;

    const {
        data,
        setData,
        post,
        put,
        processing,
        errors,
        reset,
        clearErrors,
        setError,
    } = useForm<FormData>({
            name: '',
            email: '',
            password: '',
            password_confirmation: '',
            role: '',
        });

    // 編集時、パスワード欄を明示的に開くまで隠して誤操作（意図しない変更）を防ぐ。
    const [changePassword, setChangePassword] = useState(false);
    const showPasswordFields = !isEdit || changePassword;

    // モーダルを開くたびに対象ユーザーの値で初期化する。
    useEffect(() => {
        if (!open) return;
        clearErrors();
        setChangePassword(false);
        if (user) {
            setData({
                name: user.name,
                email: user.email,
                password: '',
                password_confirmation: '',
                role: user.role,
            });
        } else {
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, user]);

    // パスワード変更をやめる（欄を閉じて入力値・エラーをクリア）。
    const cancelPasswordChange = () => {
        setChangePassword(false);
        setData('password', '');
        setData('password_confirmation', '');
        clearErrors('password', 'password_confirmation');
    };

    const handleSubmit = () => {
        // 編集で「パスワードを変更する」を開いたのに空のまま保存しようとした場合は、
        // 無変更でサイレントに成功させず、入力を促すエラーを出す（誤解防止）。
        if (isEdit && changePassword && data.password === '') {
            setError(
                'password',
                'パスワードを入力してください。変更しない場合は「パスワード変更をやめる」を選択してください。',
            );

            return;
        }

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEdit) {
            put(route('master.users.update', user.id), options);
        } else {
            post(route('master.users.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="sm:max-w-md"
                overlayClassName="bg-white/70 backdrop-blur-sm"
            >
                <DialogHeader>
                    <DialogTitle className="text-base font-bold">
                        {isEdit ? 'ユーザーを編集' : 'ユーザーを登録'}
                    </DialogTitle>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="user-name" className="flex items-center gap-1.5">
                            <RequiredBadge />
                            氏名
                        </Label>
                        <Input
                            id="user-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="例：田中 一郎"
                            maxLength={100}
                            className={errors.name ? 'border-destructive' : ''}
                        />
                        {errors.name && (
                            <p className="text-xs text-destructive">{errors.name}</p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="user-email" className="flex items-center gap-1.5">
                            <RequiredBadge />
                            メールアドレス
                        </Label>
                        <Input
                            id="user-email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="例：tanaka@company.co.jp"
                            autoComplete="off"
                            maxLength={255}
                            className={errors.email ? 'border-destructive' : ''}
                        />
                        <p className="text-xs text-muted-foreground">
                            社内メールアドレスのみ使用可。ログインIDとして使用します。
                        </p>
                        {errors.email && (
                            <p className="text-xs text-destructive">{errors.email}</p>
                        )}
                    </div>

                    {/* 編集時は既定でパスワード欄を隠し、明示的に「変更する」を押した時だけ表示する
                        （誤操作で意図せずパスワードを変更してしまうのを防ぐ） */}
                    {showPasswordFields ? (
                        <>
                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="user-password"
                                    className="flex items-center gap-1.5"
                                >
                                    <RequiredBadge />
                                    {isEdit ? '新しいパスワード' : '初期パスワード'}
                                </Label>
                                <PasswordInput
                                    id="user-password"
                                    value={data.password}
                                    onChange={(v) => setData('password', v)}
                                    placeholder="8文字以上・英字と数字を含む"
                                    autoComplete="new-password"
                                    maxLength={255}
                                    error={!!errors.password}
                                />
                                {/* パスワードは複数条件があるが Inertia は 1 フィールド 1 メッセージしか
                                    渡さないため、条件を常時ヘルパー表示して往復入力を防ぐ */}
                                <p className="text-xs text-muted-foreground">
                                    8〜255文字。英字と数字をそれぞれ1文字以上含めてください。
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    設定したパスワードは、管理者から本人へ通知してください。
                                </p>
                                {errors.password && (
                                    <p className="text-xs text-destructive">
                                        {errors.password}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label
                                    htmlFor="user-password-confirm"
                                    className="flex items-center gap-1.5"
                                >
                                    <RequiredBadge />
                                    パスワード（確認）
                                </Label>
                                <PasswordInput
                                    id="user-password-confirm"
                                    value={data.password_confirmation}
                                    onChange={(v) =>
                                        setData('password_confirmation', v)
                                    }
                                    placeholder="同じパスワードを再入力"
                                    autoComplete="new-password"
                                    maxLength={255}
                                    error={!!errors.password_confirmation}
                                />
                                {errors.password_confirmation && (
                                    <p className="text-xs text-destructive">
                                        {errors.password_confirmation}
                                    </p>
                                )}
                            </div>

                            {isEdit && (
                                <Button
                                    type="button"
                                    variant="link"
                                    onClick={cancelPasswordChange}
                                    className="h-auto p-0 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    パスワード変更をやめる
                                </Button>
                            )}
                        </>
                    ) : (
                        <div className="space-y-1.5">
                            <Button
                                type="button"
                                variant="link"
                                onClick={() => setChangePassword(true)}
                                className="h-auto p-0 text-xs"
                            >
                                パスワードを変更する
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                現在のパスワードを維持します。変更する場合のみ操作してください。
                            </p>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="user-role" className="flex items-center gap-1.5">
                            <RequiredBadge />
                            ロール
                        </Label>
                        <Select
                            value={data.role}
                            onValueChange={(v) => setData('role', v as UserRole)}
                        >
                            <SelectTrigger
                                id="user-role"
                                className={errors.role ? 'border-destructive' : ''}
                            >
                                <SelectValue placeholder="選択してください" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="general">一般</SelectItem>
                                <SelectItem value="admin">管理者</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.role && (
                            <p className="text-xs text-destructive">{errors.role}</p>
                        )}
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={processing}>
                        キャンセル
                    </Button>
                    <Button onClick={handleSubmit} disabled={processing}>
                        {processing ? '保存中...' : '保存する'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
