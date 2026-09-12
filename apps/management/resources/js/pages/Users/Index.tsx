import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { Button, TextInput } from '@/components/ui';

export interface Member { id: number; name: string; email: string; role: string; is_active: boolean }
export const roles: Record<string, string> = { admin: 'ผู้ดูแลระบบ', editor: 'ผู้แก้ไขข้อมูล', viewer: 'ดูอย่างเดียว', none: 'ยังไม่ได้กำหนดสิทธิ์' };

export default function UsersIndex({ users, filters }: {
    users: { data: Member[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
    filters: { q?: string };
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    function search(e: FormEvent) { e.preventDefault(); router.get('/admin/users', { q: query }, { preserveState: true }); }
    return <AdminLayout title="จัดการผู้ใช้" actions={<Link href="/admin/users/create" className="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white">เพิ่มผู้ใช้</Link>}>
        <Head title="จัดการผู้ใช้" />
        <p className="mb-6 max-w-2xl text-sm text-slate-600">กำหนดว่าใครเข้าถึงและแก้ไขข้อมูลธุรกิจได้ บัญชีที่ปิดใช้งานจะเข้าสู่ระบบไม่ได้ แต่ประวัติยังอยู่</p>
        <form onSubmit={search} className="mb-5 flex max-w-lg gap-2">
            <TextInput aria-label="ค้นหาชื่อหรืออีเมล" placeholder="ค้นหาชื่อหรืออีเมล" maxLength={100} value={query} onChange={e => setQuery(e.target.value)} />
            <Button type="submit" variant="secondary">ค้นหา</Button>
        </form>
        <div className="overflow-x-auto rounded-xl border border-zinc-200 bg-white">
            <table className="w-full text-left text-sm">
                <caption className="px-5 py-3 text-left text-slate-600">ผู้ใช้ทั้งหมด {users.total} คน</caption>
                <thead className="border-y border-zinc-200 bg-zinc-50"><tr>{['ผู้ใช้', 'สิทธิ์การใช้งาน', 'สถานะ', 'จัดการ'].map(label => <th scope="col" key={label} className="whitespace-nowrap px-5 py-3 font-medium">{label}</th>)}</tr></thead>
                <tbody>{users.data.map(user => <tr key={user.id} className="border-b border-zinc-100 last:border-0">
                    <td className="px-5 py-4"><div className="font-medium">{user.name}</div><div className="mt-1 break-all text-slate-600">{user.email}</div></td>
                    <td className="whitespace-nowrap px-5 py-4">{roles[user.role]}</td>
                    <td className="whitespace-nowrap px-5 py-4"><span className="rounded-md bg-zinc-100 px-2 py-1">{user.is_active ? 'ใช้งานอยู่' : 'ปิดใช้งาน'}</span></td>
                    <td className="px-5 py-4"><Link aria-label={`จัดการ ${user.name}`} className="font-medium underline underline-offset-4" href={`/admin/users/${user.id}/edit`}>จัดการ</Link></td>
                </tr>)}</tbody>
            </table>
            {!users.data.length && <p className="p-8 text-center text-slate-600">ไม่พบผู้ใช้ ลองค้นหาด้วยชื่อหรืออีเมลอื่น</p>}
        </div>
        <div className="mt-4 flex items-center justify-between text-sm">
            <span>หน้า {users.current_page} / {users.last_page}</span>
            <div className="flex gap-4">{users.prev_page_url && <Link href={users.prev_page_url}>ก่อนหน้า</Link>}{users.next_page_url && <Link href={users.next_page_url}>ถัดไป</Link>}</div>
        </div>
    </AdminLayout>;
}
