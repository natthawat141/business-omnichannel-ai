import { Head, Link, useForm } from '@inertiajs/react';
import { Key } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { Button, Field, TextInput, Toggle } from '@/components/ui';
import { roles, type Member } from './Index';

const eventLabels: Record<string, string> = {
    created: 'เพิ่มบัญชี',
    access_changed: 'เปลี่ยนสิทธิ์หรือการเข้าถึง',
    profile_updated: 'แก้ไขชื่อผู้ใช้',
    password_link_created: 'สร้างลิงก์ตั้งรหัสผ่าน',
    password_set: 'ตั้งรหัสผ่านแล้ว',
    google_registered_pending: 'ลงทะเบียนผ่าน Google (รอดำเนินการ)',
    google_linked: 'เชื่อมต่อบัญชี Google สำเร็จ',
    approved: 'อนุมัติการเข้าใช้งาน',
    rejected: 'ปฏิเสธคำขอเข้าใช้งาน',
};

export default function UserForm({ member, events }: { member: Member | null; events: { id: number; actor_id: number | null; event: string; created_at: string }[] }) {
    const form = useForm({ name: member?.name ?? '', email: member?.email ?? '', role: member?.role === 'none' ? 'viewer' : member?.role ?? 'viewer', is_active: member?.is_active ?? true });
    const [link, setLink] = useState('');
    const [minutes, setMinutes] = useState(60);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    function submit(e: FormEvent) {
        e.preventDefault();
        if (member && (member.role !== form.data.role || member.is_active !== form.data.is_active || member.email !== form.data.email)
            && !window.confirm('การเปลี่ยนสิทธิ์ สถานะ หรืออีเมลจะออกจากระบบและเพิกถอนคีย์ที่ผูกกับบัญชีนี้ ต้องการบันทึกหรือไม่?')) return;
        setLink('');
        if (member) form.put(`/admin/users/${member.id}`); else form.post('/admin/users');
    }
    async function createLink() {
        if (!member) return;
        setBusy(true); setMessage(''); setLink('');
        try {
            const csrf = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11) ?? '';
            const response = await fetch(`/admin/users/${member.id}/password-link`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) } });
            if (!response.ok) throw new Error('สร้างลิงก์ไม่สำเร็จ ตรวจสอบสถานะบัญชี หรือลองใหม่ภายหลัง');
            const result = await response.json(); setLink(result.url); setMinutes(result.expires_minutes);
        } catch (error) { setMessage(error instanceof Error ? error.message : 'เกิดข้อผิดพลาด กรุณาลองใหม่'); }
        finally { setBusy(false); }
    }
    async function copyLink() {
        try { await navigator.clipboard.writeText(link); setMessage('คัดลอกแล้ว ส่งให้เจ้าของบัญชีเป็นการส่วนตัวเท่านั้น'); }
        catch { setMessage('คัดลอกอัตโนมัติไม่ได้ กรุณาเลือกและคัดลอกลิงก์ด้านล่าง'); }
    }
    return <AdminLayout title={member ? 'จัดการบัญชีผู้ใช้' : 'เพิ่มผู้ใช้'}>
        <Head title={member ? 'จัดการบัญชีผู้ใช้' : 'เพิ่มผู้ใช้'} />
        <Link href="/admin/users" className="mb-6 inline-block text-sm underline underline-offset-4">กลับไปรายชื่อผู้ใช้</Link>
        <div className="max-w-2xl space-y-8">
            {member && (
                <section className="rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-zinc-800 dark:bg-zinc-900/50 space-y-2">
                    <h2 className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-zinc-400">
                        ช่องทางการเข้าสู่ระบบ (Authentication)
                    </h2>
                    <div className="flex items-center gap-2">
                        {member.auth_provider === 'both' ? (
                            <span className="inline-flex items-center gap-1.5 rounded-md border border-purple-200 bg-purple-50 px-2.5 py-1 text-xs font-medium text-purple-700 dark:border-purple-800 dark:bg-purple-950/40 dark:text-purple-300">
                                <Key className="h-3.5 w-3.5" />
                                ทั้งสองช่องทาง (Google + รหัสผ่าน)
                            </span>
                        ) : member.auth_provider === 'google' ? (
                            <span className="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300">
                                Google เท่านั้น
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <Key className="h-3.5 w-3.5" />
                                รหัสผ่านเท่านั้น
                            </span>
                        )}
                    </div>
                    <p className="text-xs text-slate-600 dark:text-zinc-400">
                        {member.auth_provider === 'both'
                            ? 'ผู้ใช้นี้สามารถเข้าสู่ระบบได้ทั้งการกดปุ่ม Google และการกรอกอีเมล/รหัสผ่าน'
                            : member.auth_provider === 'google'
                              ? 'ผู้ใช้นี้เข้าสู่ระบบผ่าน Google เท่านั้น หากต้องการให้ล็อกอินด้วยรหัสผ่านได้ด้วย สามารถสร้างลิงก์ตั้งรหัสผ่านด้านล่าง'
                              : 'ผู้ใช้นี้เข้าสู่ระบบผ่านรหัสผ่าน หากล็อกอินด้วย Google ด้วยอีเมลนี้ ระบบจะเชื่อมต่อทั้งสองช่องทางให้อัตโนมัติ'}
                    </p>
                </section>
            )}

            <form onSubmit={submit} className="space-y-5">
                <Field label="ชื่อผู้ใช้" required error={form.errors.name}><TextInput aria-label="ชื่อผู้ใช้" required maxLength={255} value={form.data.name} onChange={e => form.setData('name', e.target.value)} /></Field>
                <Field label="อีเมลสำหรับเข้าสู่ระบบ" required error={form.errors.email}><TextInput aria-label="อีเมลสำหรับเข้าสู่ระบบ" required type="email" maxLength={255} autoComplete="off" value={form.data.email} onChange={e => form.setData('email', e.target.value)} /></Field>
                <Field label="สิทธิ์การใช้งาน" error={form.errors.role}>
                    <select aria-label="สิทธิ์การใช้งาน" className="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm" value={form.data.role} onChange={e => form.setData('role', e.target.value)}>
                        {['viewer', 'editor', 'admin'].map(role => <option key={role} value={role}>{roles[role]}</option>)}
                    </select>
                </Field>
                <p className="text-sm text-slate-600">{form.data.role === 'admin' ? 'จัดการข้อมูล ผู้ใช้ เอกสาร และคีย์เชื่อมต่อทั้งหมด' : form.data.role === 'editor' ? 'เพิ่มและแก้ไขข้อมูลธุรกิจ แต่จัดการผู้ใช้ เอกสารนำเข้า และคีย์ไม่ได้' : 'อ่านรายการข้อมูลธุรกิจ แต่เพิ่ม แก้ไข หรือลบไม่ได้'}</p>
                <Field label="สถานะบัญชี" error={form.errors.is_active}><Toggle label="เปิดใช้งานบัญชี" checked={form.data.is_active} onChange={value => form.setData('is_active', value)} /></Field>
                <Button type="submit" disabled={form.processing}>{form.processing ? 'กำลังบันทึก…' : member ? 'บันทึกผู้ใช้' : 'เพิ่มผู้ใช้'}</Button>
            </form>
            {member && <section className="space-y-3 border-t border-zinc-200 pt-6">
                <h2 className="text-lg font-semibold">ตั้งรหัสผ่าน</h2>
                <p className="text-sm text-slate-600">สร้างลิงก์ใช้ครั้งเดียวแล้วส่งให้เจ้าของบัญชีเป็นการส่วนตัว ลิงก์ใหม่จะแทนที่ลิงก์เก่า ไม่มีการส่งอีเมลอัตโนมัติ</p>
                <Button type="button" variant="secondary" disabled={busy || !member.is_active || form.isDirty} onClick={createLink}>{busy ? 'กำลังสร้างลิงก์…' : 'สร้างลิงก์ตั้งรหัสผ่าน'}</Button>
                {form.isDirty && <p className="text-sm text-slate-600">บันทึกการแก้ไขก่อนสร้างลิงก์</p>}
                {link && <div className="space-y-2"><p className="text-sm">หมดอายุใน {minutes} นาที ห้ามนำไปวางในแชต AI หรือเอกสารสาธารณะ</p><TextInput aria-label="ลิงก์ตั้งรหัสผ่านส่วนตัว" readOnly value={link} onFocus={e => e.target.select()} /><Button type="button" onClick={copyLink}>คัดลอกลิงก์ส่วนตัว</Button><Button type="button" variant="secondary" onClick={() => setLink('')}>ซ่อนลิงก์</Button></div>}
                <p role="status" className="text-sm">{message}</p>
            </section>}
            {member && <section className="border-t border-zinc-200 pt-6"><h2 className="mb-3 text-lg font-semibold">ประวัติการจัดการบัญชี</h2><p className="mb-3 text-sm text-slate-600">20 รายการล่าสุด ไม่รวมประวัติแก้ไขทรัพย์หรือบทสนทนา</p>
                <ul className="divide-y divide-zinc-200">{events.map(event => <li key={event.id} className="py-3 text-sm"><p>{eventLabels[event.event] ?? event.event}</p><p className="mt-1 text-slate-600">{event.created_at} · {event.actor_id ? `ผู้ดูแล #${event.actor_id}` : 'เจ้าของลิงก์ตั้งรหัสผ่าน'}</p></li>)}</ul>
                {!events.length && <p className="text-sm text-slate-600">ยังไม่มีประวัติการเปลี่ยนแปลง</p>}
            </section>}
        </div>
    </AdminLayout>;
}
