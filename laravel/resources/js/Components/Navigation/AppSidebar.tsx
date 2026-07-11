import { cn } from '@/lib/utils';
import { PageProps } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart2,
    Briefcase,
    FileDown,
    LayoutDashboard,
    LogOut,
    Settings,
    Users,
} from 'lucide-react';

interface NavItem {
    label: string;
    href: string;
    match: string;
    icon: React.ReactNode;
    adminOnly?: boolean;
}

interface NavSection {
    sectionLabel: string;
    items: NavItem[];
}

const navSections: NavSection[] = [
    {
        sectionLabel: 'メイン',
        items: [
            {
                label: 'ダッシュボード',
                href: '/dashboard',
                match: '/dashboard',
                icon: <LayoutDashboard className="h-4 w-4" />,
            },
        ],
    },
    {
        sectionLabel: '人材',
        items: [
            {
                label: '人材一覧',
                href: '/engineers',
                match: '/engineers',
                icon: <Users className="h-4 w-4" />,
            },
        ],
    },
    {
        sectionLabel: '案件',
        items: [
            {
                label: '案件一覧',
                href: '/projects',
                match: '/projects',
                icon: <Briefcase className="h-4 w-4" />,
            },
        ],
    },
    {
        sectionLabel: '進捗管理',
        items: [
            {
                label: 'パイプライン',
                href: '/pipelines',
                match: '/pipelines',
                icon: <BarChart2 className="h-4 w-4" />,
            },
        ],
    },
    {
        sectionLabel: '管理',
        items: [
            {
                label: 'CSV入出力',
                href: '/csv',
                match: '/csv',
                icon: <FileDown className="h-4 w-4" />,
            },
            {
                label: 'マスタ管理',
                href: '/master',
                match: '/master',
                icon: <Settings className="h-4 w-4" />,
                adminOnly: true,
            },
        ],
    },
];

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
    return (
        <Link
            href={item.href}
            className={cn(
                'flex items-center gap-2 border-l-[3px] px-3 py-2 text-sm transition-colors',
                active
                    ? 'border-sidebar-primary bg-sidebar-accent font-bold text-sidebar-foreground'
                    : 'border-transparent text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
            )}
        >
            {item.icon}
            {item.label}
        </Link>
    );
}

export default function AppSidebar() {
    const { auth } = usePage<PageProps>().props;
    const { url } = usePage();

    const pathname = url.split('?')[0];
    const isActive = (match: string) =>
        pathname === match || pathname.startsWith(match + '/');

    const isAdmin = auth.user.role === 'admin';
    const roleLabel = isAdmin ? '管理者' : '一般営業';

    return (
        <aside className="flex h-screen w-[220px] shrink-0 flex-col border-r border-sidebar-border bg-sidebar">
            {/* ロゴ */}
            <div className="border-b-2 border-white/30 px-[14px] py-4">
                <span className="text-[15px] font-bold tracking-widest text-white">
                    Nexus
                </span>
            </div>

            {/* ナビゲーション */}
            <nav className="flex-1 overflow-y-auto py-2">
                {navSections.map((section) => {
                    const visibleItems = section.items.filter(
                        (item) => !item.adminOnly || isAdmin,
                    );
                    if (visibleItems.length === 0) return null;

                    return (
                        <div key={section.sectionLabel}>
                            <p className="px-[14px] pb-1 pt-[10px] text-[10px] font-bold uppercase tracking-widest text-sidebar-foreground/50">
                                {section.sectionLabel}
                            </p>
                            {visibleItems.map((item) => (
                                <NavLink
                                    key={item.href}
                                    item={item}
                                    active={isActive(item.match)}
                                />
                            ))}
                        </div>
                    );
                })}
            </nav>

            {/* ユーザー情報・ログアウト */}
            <div className="shrink-0 border-t border-white/30">
                <div className="px-[14px] py-3">
                    <p className="truncate text-[12px] text-sidebar-foreground/100">
                        {auth.user.name}（{roleLabel}）
                    </p>
                    {/* <p className="text-[10px] text-sidebar-foreground/50">{roleLabel}</p> */}
                </div>
                <Link
                    href={route('logout')}
                    method="post"
                    as="button"
                    className="flex w-full items-center gap-2 border-l-[3px] border-t border-transparent border-t-white/10 px-3 py-2 text-sm text-sidebar-foreground/100 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    <LogOut className="h-4 w-4" />
                    ログアウト
                </Link>
            </div>
        </aside>
    );
}
