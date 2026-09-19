import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Key, Search, UserCheck, UserX } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { Button, TextInput } from '@/components/ui';
import { routes } from '@/lib/routes';

export interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
    auth_provider?: string;
    approval_status?: string;
    created_at?: string;
}

export const roles: Record<string, string> = {
    admin: 'ผู้ดูแลระบบ',
    editor: 'ผู้แก้ไขข้อมูล',
    viewer: 'ดูอย่างเดียว',
    none: 'ยังไม่ได้กำหนดสิทธิ์',
};

export default function UsersIndex({
    users,
    filters,
    pendingCount = 0,
}: {
    users: {
        data: Member[];
        total: number;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { q?: string; status?: string; provider?: string };
    pendingCount?: number;
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    const currentStatus = filters.status ?? 'all';
    const currentProvider = filters.provider ?? 'all';
    const [approvingUser, setApprovingUser] = useState<Member | null>(null);
    const [selectedRole, setSelectedRole] = useState<'admin' | 'editor' | 'viewer'>('viewer');
    const [processing, setProcessing] = useState(false);

    function search(e: FormEvent) {
        e.preventDefault();
        router.get(
            routes.users.index,
            {
                q: query,
                status: currentStatus !== 'all' ? currentStatus : undefined,
                provider: currentProvider !== 'all' ? currentProvider : undefined,
            },
            { preserveState: true },
        );
    }

    function handleFilterStatus(status: string) {
        router.get(
            routes.users.index,
            {
                q: query || undefined,
                status: status !== 'all' ? status : undefined,
                provider: currentProvider !== 'all' ? currentProvider : undefined,
            },
            { preserveState: true },
        );
    }

    function handleFilterProvider(provider: string) {
        router.get(
            routes.users.index,
            {
                q: query || undefined,
                status: currentStatus !== 'all' ? currentStatus : undefined,
                provider: provider !== 'all' ? provider : undefined,
            },
            { preserveState: true },
        );
    }

    function handleApproveSubmit(e: FormEvent) {
        e.preventDefault();
        if (!approvingUser) return;
        setProcessing(true);
        router.post(
            routes.users.approve(approvingUser.id),
            { role: selectedRole },
            {
                onSuccess: () => {
                    setApprovingUser(null);
                    setProcessing(false);
                },
                onError: () => setProcessing(false),
            },
        );
    }

    function handleReject(user: Member) {
        if (!window.confirm(`ต้องการปฏิเสธคำขอเข้าใช้งานของ "${user.name}" (${user.email}) หรือไม่?`)) {
            return;
        }
        router.post(routes.users.reject(user.id));
    }

    return (
        <AdminLayout
            title="จัดการผู้ใช้"
            actions={
                <Link
                    href={routes.users.create}
                    className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white"
                >
                    เพิ่มผู้ใช้
                </Link>
            }
        >
            <Head title="จัดการผู้ใช้" />
            <p className="mb-6 max-w-2xl text-sm text-slate-600 dark:text-zinc-400">
                กำหนดว่าใครเข้าถึงและแก้ไขข้อมูลธุรกิจได้ บัญชีที่ปิดใช้งานจะเข้าสู่ระบบไม่ได้ แต่ประวัติยังอยู่
            </p>

            {/* Filter Tabs */}
            <div className="mb-4 flex flex-wrap items-center gap-2 border-b border-zinc-200 dark:border-zinc-800 pb-3">
                {[
                    { key: 'all', label: 'ทั้งหมด' },
                    { key: 'pending', label: 'รอดำเนินการ', count: pendingCount },
                    { key: 'active', label: 'ใช้งานอยู่' },
                    { key: 'inactive', label: 'ปิดใช้งาน' },
                ].map((tab) => (
                    <button
                        key={tab.key}
                        type="button"
                        onClick={() => handleFilterStatus(tab.key)}
                        className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                            currentStatus === tab.key
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-slate-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'
                        }`}
                    >
                        <span>{tab.label}</span>
                        {typeof tab.count === 'number' && tab.count > 0 && (
                            <span
                                className={`rounded-full px-1.5 py-0.2 text-[10px] font-semibold ${
                                    currentStatus === tab.key
                                        ? 'bg-amber-400 text-zinc-950'
                                        : 'bg-amber-500/20 text-amber-600 dark:text-amber-400'
                                }`}
                            >
                                {tab.count}
                            </span>
                        )}
                    </button>
                ))}
            </div>

            {/* Search and Provider Filter form */}
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <form onSubmit={search} className="flex flex-1 max-w-md gap-2">
                    <TextInput
                        aria-label="ค้นหาชื่อหรืออีเมล"
                        placeholder="ค้นหาชื่อหรืออีเมล"
                        maxLength={100}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="h-4 w-4" />
                        ค้นหา
                    </Button>
                </form>

                <div className="flex items-center gap-2">
                    <label htmlFor="provider-filter" className="text-xs text-slate-600 dark:text-zinc-400">
                        ช่องทาง:
                    </label>
                    <select
                        id="provider-filter"
                        aria-label="กรองตามช่องทางการเข้าสู่ระบบ"
                        className="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        value={currentProvider}
                        onChange={(e) => handleFilterProvider(e.target.value)}
                    >
                        <option value="all">ทุกช่องทาง</option>
                        <option value="password">รหัสผ่านเท่านั้น</option>
                        <option value="google">Google เท่านั้น</option>
                        <option value="both">ทั้ง Google และ รหัสผ่าน</option>
                    </select>
                </div>
            </div>

            {/* Table */}
            <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
                <table className="w-full text-left text-sm">
                    <caption className="px-5 py-3 text-left text-slate-600 dark:text-zinc-400">
                        ผู้ใช้ทั้งหมด {users.total} คน
                    </caption>
                    <thead className="border-y border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/50">
                        <tr>
                            {['ผู้ใช้', 'ช่องทาง', 'สิทธิ์การใช้งาน', 'สถานะ', 'จัดการ'].map((label) => (
                                <th scope="col" key={label} className="whitespace-nowrap px-5 py-3 font-medium">
                                    {label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {users.data.map((user) => {
                            const isPending = user.approval_status === 'pending';

                            return (
                                <tr
                                    key={user.id}
                                    className={`border-b border-zinc-100 dark:border-zinc-800 last:border-0 ${
                                        isPending ? 'bg-amber-50/40 dark:bg-amber-950/10' : ''
                                    }`}
                                >
                                    <td className="px-5 py-4">
                                        <div className="font-medium text-zinc-900 dark:text-zinc-100">{user.name}</div>
                                        <div className="mt-0.5 break-all text-xs text-slate-500 dark:text-zinc-400">
                                            {user.email}
                                        </div>
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-4">
                                        {user.auth_provider === 'both' ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-md border border-purple-200 bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700 dark:border-purple-800 dark:bg-purple-950/40 dark:text-purple-300">
                                                <svg className="h-3 w-3" viewBox="0 0 24 24">
                                                    <path
                                                        fill="#4285F4"
                                                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                                                    />
                                                    <path
                                                        fill="#34A853"
                                                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                                    />
                                                    <path
                                                        fill="#FBBC05"
                                                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"
                                                    />
                                                    <path
                                                        fill="#EA4335"
                                                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"
                                                    />
                                                </svg>
                                                <Key className="h-3 w-3 text-purple-600 dark:text-purple-400" />
                                                Google + รหัสผ่าน
                                            </span>
                                        ) : user.auth_provider === 'google' ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
                                                <svg className="h-3 w-3" viewBox="0 0 24 24">
                                                    <path
                                                        fill="#4285F4"
                                                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                                                    />
                                                    <path
                                                        fill="#34A853"
                                                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                                    />
                                                    <path
                                                        fill="#FBBC05"
                                                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"
                                                    />
                                                    <path
                                                        fill="#EA4335"
                                                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"
                                                    />
                                                </svg>
                                                Google
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                <Key className="h-3 w-3 text-zinc-400" />
                                                รหัสผ่าน
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-4 text-zinc-700 dark:text-zinc-300">
                                        {roles[user.role] ?? user.role}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-4">
                                        {isPending ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                                                รอดำเนินการ
                                            </span>
                                        ) : user.is_active ? (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300">
                                                ใช้งานอยู่
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                                ปิดใช้งาน
                                            </span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap px-5 py-4">
                                        {isPending ? (
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        setSelectedRole('viewer');
                                                        setApprovingUser(user);
                                                    }}
                                                    className="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                                                >
                                                    <UserCheck className="h-3.5 w-3.5" />
                                                    อนุมัติ
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleReject(user)}
                                                    className="inline-flex items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-100 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400"
                                                >
                                                    <UserX className="h-3.5 w-3.5" />
                                                    ปฏิเสธ
                                                </button>
                                            </div>
                                        ) : (
                                            <Link
                                                aria-label={`จัดการ ${user.name}`}
                                                className="font-medium text-zinc-900 underline underline-offset-4 hover:text-zinc-600 dark:text-zinc-100 dark:hover:text-zinc-300"
                                                href={routes.users.edit(user.id)}
                                            >
                                                จัดการ
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                {!users.data.length && (
                    <p className="p-8 text-center text-slate-600 dark:text-zinc-400">
                        ไม่พบผู้ใช้ ลองค้นหาด้วยชื่อหรือสถานะอื่น
                    </p>
                )}
            </div>

            {/* Pagination */}
            <div className="mt-4 flex items-center justify-between text-sm text-slate-600 dark:text-zinc-400">
                <span>
                    หน้า {users.current_page} / {users.last_page}
                </span>
                <div className="flex gap-4">
                    {users.prev_page_url && (
                        <Link href={users.prev_page_url} className="underline underline-offset-4">
                            ก่อนหน้า
                        </Link>
                    )}
                    {users.next_page_url && (
                        <Link href={users.next_page_url} className="underline underline-offset-4">
                            ถัดไป
                        </Link>
                    )}
                </div>
            </div>

            {/* Approve Modal Dialog */}
            {approvingUser && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                                <CheckCircle2 className="h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                                    อนุมัติการเข้าใช้งาน
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-zinc-400">
                                    {approvingUser.name} ({approvingUser.email})
                                </p>
                            </div>
                        </div>

                        <form onSubmit={handleApproveSubmit} className="mt-5 space-y-4">
                            <div>
                                <label className="block text-xs font-medium text-slate-700 dark:text-zinc-300">
                                    กำหนดสิทธิ์การใช้งาน (Role)
                                </label>
                                <select
                                    className="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                    value={selectedRole}
                                    onChange={(e) =>
                                        setSelectedRole(e.target.value as 'admin' | 'editor' | 'viewer')
                                    }
                                >
                                    <option value="viewer">ดูอย่างเดียว (Viewer) - แนะนำ</option>
                                    <option value="editor">ผู้แก้ไขข้อมูล (Editor)</option>
                                    <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                                </select>
                                <p className="mt-1 text-xs text-slate-500 dark:text-zinc-400">
                                    {selectedRole === 'admin'
                                        ? 'มีสิทธิ์เต็มในการจัดการข้อมูล ผู้ใช้ และการตั้งค่าระบบ'
                                        : selectedRole === 'editor'
                                          ? 'เพิ่มและแก้ไขข้อมูลอสังหาริมทรัพย์และเนื้อหาได้'
                                          : 'อ่านและดูข้อมูลในระบบเท่านั้น (ปลอดภัยที่สุด)'}
                                </p>
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={processing}
                                    onClick={() => setApprovingUser(null)}
                                >
                                    ยกเลิก
                                </Button>
                                <Button type="submit" disabled={processing} className="bg-emerald-600 hover:bg-emerald-700">
                                    {processing ? 'กำลังอนุมัติ...' : 'ยืนยันอนุมัติ'}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}
