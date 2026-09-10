import TruncatedText from '@/Components/Common/TruncatedText';
import { Button } from '@/Components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { cn } from '@/lib/utils';
import { MasterUser } from '@/types/master';
import { Pencil, Trash2 } from 'lucide-react';

interface Props {
    users: MasterUser[];
    /** ログイン中ユーザーのID（自分自身は削除ボタンを無効化する） */
    currentUserId: number;
    onEdit: (user: MasterUser) => void;
    onDelete: (user: MasterUser) => void;
}

/** 列幅比率（合計 100%）。進捗管理・完了済みタブのテーブル方式に準拠。
 *  氏名 / メール / ロール / 最終ログイン / 操作。
 *  最終ログイン・操作は内容が短いため狭め、氏名・メールへ配分して余白を詰める。 */
const COLUMN_WIDTHS = [29, 35, 12, 15, 9];

/** 最終ログイン日時（ISO8601）を日本語日時に整形。null は「未ログイン」。 */
function formatLastLogin(value: string | null): string {
    if (!value) return '未ログイン';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('ja-JP', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function UserTable({
    users,
    currentUserId,
    onEdit,
    onDelete,
}: Props) {
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
                        <Th>氏名</Th>
                        <Th>メールアドレス（ログインID）</Th>
                        <Th>ロール</Th>
                        <Th>最終ログイン</Th>
                        <Th>
                            <span className="sr-only">操作</span>
                        </Th>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {users.map((user) => (
                        <TableRow key={user.id} className="hover:bg-muted/30">
                            <TableCell className="px-3 py-2.5 font-bold text-foreground">
                                <TruncatedText as="div" text={user.name} />
                            </TableCell>
                            <TableCell className="px-3 py-2.5 text-muted-foreground">
                                <TruncatedText as="div" text={user.email} />
                            </TableCell>
                            <TableCell className="px-3 py-2.5">
                                {/* StatusBadge 等と同じ「淡色地＋色付きボーダー＋濃色文字」規約。
                                    プライマリー（操作色）は使わず、属性として管理者=indigo / 一般=gray */}
                                <span
                                    className={cn(
                                        'inline-block rounded-full border px-2.5 py-0.5 text-xs font-bold',
                                        user.role === 'admin'
                                            ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                            : 'border-gray-400 bg-gray-50 text-gray-600',
                                    )}
                                >
                                    {user.role_label}
                                </span>
                            </TableCell>
                            <TableCell className="px-3 py-2.5 text-muted-foreground">
                                {formatLastLogin(user.last_login_at)}
                            </TableCell>
                            <TableCell className="px-3 py-2.5">
                                <div className="flex items-center gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => onEdit(user)}
                                        className="h-7 w-7 text-muted-foreground hover:bg-muted hover:text-foreground [&_svg]:size-3.5"
                                        aria-label="編集"
                                        title="編集"
                                    >
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => onDelete(user)}
                                        disabled={user.id === currentUserId}
                                        className="h-7 w-7 text-destructive hover:bg-destructive/10 hover:text-destructive disabled:text-muted-foreground/40 [&_svg]:size-3.5"
                                        aria-label="削除"
                                        title={
                                            user.id === currentUserId
                                                ? '自分自身は削除できません'
                                                : '削除'
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

// shadcn TableHead の既定（h-12・px-4・font-medium）をコンパクト表示に上書きするラッパ
function Th({ children }: { children: React.ReactNode }) {
    return (
        <TableHead className="h-auto px-3 py-2.5 text-left text-[11px] font-bold text-muted-foreground whitespace-nowrap">
            {children}
        </TableHead>
    );
}
