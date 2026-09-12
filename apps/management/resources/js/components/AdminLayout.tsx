import { Link, router, usePage } from '@inertiajs/react';
import {
    Package,
    Tags,
    HelpCircle,
    BookOpen,
    BookOpenCheck,
    KeyRound,
    FileSpreadsheet,
    LayoutDashboard,
    LogOut,
    Menu,
    Moon,
    Sun,
    CheckCircle2,
    AlertCircle,
    Building2,
    Users,
    ClipboardCheck,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import type { PageProps } from '@/types';
import { routes } from '@/lib/routes';

interface NavItem {
    label: string;
    href: string;
    icon: typeof LayoutDashboard;
    match: string;
}

const nav: NavItem[] = [
    { label: 'จัดการผู้ใช้', href: '/admin/users', icon: Users, match: '/admin/users' },
    { label: 'แดชบอร์ด', href: routes.dashboard, icon: LayoutDashboard, match: '/admin/dashboard' },
    { label: 'คู่มือ / Guide', href: routes.guide, icon: BookOpenCheck, match: '/admin/guide' },
    { label: 'ประเภททรัพย์', href: routes.categories.index, icon: Tags, match: '/admin/package-categories' },
    { label: 'รายการทรัพย์', href: routes.packages.index, icon: Package, match: '/admin/packages' },
    { label: 'คำถามพบบ่อย', href: routes.faqs.index, icon: HelpCircle, match: '/admin/faqs' },
    { label: 'คลังความรู้', href: routes.knowledge.index, icon: BookOpen, match: '/admin/knowledge' },
    { label: 'โปรไฟล์ธุรกิจ', href: routes.businessProfile.edit, icon: Building2, match: '/admin/business-profile' },
    { label: 'นำเข้ารายการทรัพย์', href: routes.imports.index, icon: FileSpreadsheet, match: '/admin/imports' },
    { label: 'ตรวจข้อเสนอ AI', href: routes.agentChanges.index, icon: ClipboardCheck, match: '/admin/agent-changes' },
    { label: 'โทเคน API', href: routes.apiTokens.index, icon: KeyRound, match: '/admin/api-tokens' },
    { label: 'เชื่อมต่อ AI', href: '/admin/ai-setup', icon: KeyRound, match: '/admin/ai-setup' },
];

const THEME_STORAGE_KEY = 'aion3-theme';

function FlashMessages() {
    const { flash } = usePage<PageProps>().props;
    const [visible, setVisible] = useState(true);

    useEffect(() => {
        setVisible(true);
        if (flash.success || flash.error) {
            const t = setTimeout(() => setVisible(false), 5000);
            return () => clearTimeout(t);
        }
    }, [flash.success, flash.error]);

    if (!visible || (!flash.success && !flash.error)) {
        return null;
    }

    return (
        <div className="fixed top-4 right-4 z-50 w-full max-w-sm">
            {flash.success && (
                <div className="mb-2 flex items-start gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-800 shadow">
                    <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{flash.success}</span>
                </div>
            )}
            {flash.error && (
                <div className="flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{flash.error}</span>
                </div>
            )}
        </div>
    );
}

export default function AdminLayout({ title, actions, children }: { title: string; actions?: ReactNode; children: ReactNode }) {
    const page = usePage<PageProps>();
    const auth = page.props.auth;
    const currentUrl = page.url;
    const [open, setOpen] = useState(false);
    const [darkMode, setDarkMode] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return document.documentElement.dataset.theme === 'dark';
    });

    useEffect(() => {
        document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
        try {
            window.localStorage.setItem(THEME_STORAGE_KEY, darkMode ? 'dark' : 'light');
        } catch {
            // Private browsing or a blocked storage policy should not break the UI toggle.
        }
    }, [darkMode]);

    function logout() {
        router.post(routes.logout);
    }

    const isActive = (match: string) => currentUrl.startsWith(match);

    const sidebar = (
        <nav className="flex h-full flex-col gap-1 p-3">
            <div className="px-3 py-4">
                <span className="brand-logo-frame">
                    <img
                        src="/img/aion3-logo.png"
                        alt="Aion3"
                        className="brand-logo h-12 w-auto max-w-full object-contain object-left"
                    />
                </span>
                <p className="mt-1 text-sm font-semibold tracking-tight text-zinc-900">Property Management</p>
                <p className="text-xs text-slate-500">จัดการทรัพย์และข้อมูลที่ AI ใช้ตอบ</p>
            </div>
            {nav.filter((item) => {
                if (auth.user?.role === 'admin') return true;
                if (['/admin/users', '/admin/api-tokens', '/admin/ai-setup', '/admin/agent-changes', '/admin/documents'].includes(item.match)) return false;
                if (!auth.user?.can_edit && ['/admin/imports', '/admin/business-profile'].includes(item.match)) return false;
                return true;
            }).map((item) => {
                const Icon = item.icon;
                return (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={() => setOpen(false)}
                        className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${
                            isActive(item.match)
                                ? 'bg-zinc-900 text-white'
                                : 'text-slate-600 hover:bg-slate-100'
                        }`}
                    >
                        <Icon className="h-4 w-4" />
                        {item.label}
                    </Link>
                );
            })}
            <div className="mt-auto border-t border-slate-200 px-3 pt-3">
                <button
                    type="button"
                    onClick={() => setDarkMode((current) => !current)}
                    aria-pressed={darkMode}
                    title={darkMode ? 'เปลี่ยนเป็นโหมดสว่าง' : 'เปลี่ยนเป็นโหมดมืด'}
                    className="mb-2 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-400"
                >
                    {darkMode ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
                    {darkMode ? 'โหมดสว่าง' : 'โหมดมืด'}
                </button>
                <p className="truncate px-1 text-xs text-slate-500">{auth.user?.email}</p>
                <button
                    type="button"
                    onClick={logout}
                    className="mt-2 flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-400"
                >
                    <LogOut className="h-4 w-4" />
                    ออกจากระบบ
                </button>
            </div>
        </nav>
    );

    return (
        <div className="min-h-screen lg:flex">
            <FlashMessages />

            {/* Desktop sidebar */}
            <aside className="sticky top-0 hidden h-screen w-64 shrink-0 border-r border-slate-200 bg-white lg:block">{sidebar}</aside>

            {/* Mobile drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/30" onClick={() => setOpen(false)} />
                    <aside className="absolute left-0 top-0 h-full w-64 bg-white shadow-xl">{sidebar}</aside>
                </div>
            )}

            <div className="flex min-h-screen min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:px-6">
                    <button className="lg:hidden" onClick={() => setOpen(true)} aria-label="เมนู">
                        <Menu className="h-5 w-5 text-slate-600" />
                    </button>
                    <h1 className="min-w-0 flex-1 truncate text-lg font-semibold text-slate-800">{title}</h1>
                    {actions}
                </header>

                <main className="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 lg:px-8">{children}</main>

            </div>
        </div>
    );
}
