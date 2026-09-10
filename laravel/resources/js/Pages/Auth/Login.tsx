import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { KeyboardEvent } from 'react';

export default function Login({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        email: string;
        password: string;
        remember: boolean;
    }>({
        email: '',
        password: '',
        remember: false,
    });

    // CLAUDE.md 規約に従い <form> は使わず、useForm().post() をボタン onClick で実行する。
    const submit = () => {
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    // ネイティブ <form> の暗黙 submit を失う代わりに、入力欄での Enter 送信を担保する。
    const handleEnter = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' && !processing) {
            e.preventDefault();
            submit();
        }
    };

    return (
        <GuestLayout>
            <Head title="ログイン" />

            {/* システム名（WF_01：カード先頭・中央・下線区切り。ロゴ画像は無し） */}
            <div className="mb-8 border-b border-gray-200 pb-6 text-center">
                <span className="text-2xl font-bold tracking-wider text-primary">
                    Nexus
                </span>
            </div>

            {status && (
                <div className="mb-4 text-sm font-medium text-green-600">
                    {status}
                </div>
            )}

            <div className="flex flex-col gap-5">
                <div>
                    <InputLabel htmlFor="email" value="メールアドレス" />

                    <TextInput
                        id="email"
                        type="email"
                        name="email"
                        value={data.email}
                        className="mt-1.5 block w-full"
                        autoComplete="username"
                        isFocused={true}
                        onChange={(e) => setData('email', e.target.value)}
                        onKeyDown={handleEnter}
                    />

                    <InputError message={errors.email} className="mt-2" />
                </div>

                <div>
                    <InputLabel htmlFor="password" value="パスワード" />

                    <TextInput
                        id="password"
                        type="password"
                        name="password"
                        value={data.password}
                        className="mt-1.5 block w-full"
                        autoComplete="current-password"
                        onChange={(e) => setData('password', e.target.value)}
                        onKeyDown={handleEnter}
                    />

                    <InputError message={errors.password} className="mt-2" />
                </div>

                <label className="flex items-center">
                    <Checkbox
                        name="remember"
                        checked={data.remember}
                        onChange={(e) =>
                            setData('remember', e.target.checked)
                        }
                    />
                    <span className="ms-2 text-sm text-gray-600">
                        ログイン情報を保存する
                    </span>
                </label>

                {/* WF_01：ログインボタンは全幅 */}
                <PrimaryButton
                    type="button"
                    className="w-full"
                    disabled={processing}
                    onClick={submit}
                >
                    ログイン
                </PrimaryButton>
            </div>
        </GuestLayout>
    );
}
