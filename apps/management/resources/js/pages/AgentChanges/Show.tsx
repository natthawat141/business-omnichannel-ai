import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Check, CheckCircle2, ClipboardCheck, FileText, ShieldAlert, X } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import { Button, Card } from '@/components/ui';
import { routes } from '@/lib/routes';

interface Operation { sequence: number; entity: string; action: string; target_id: number | null; client_ref: string | null; parent_client_ref: string | null; expected_version: number | null; payload: Record<string, unknown>; sources: Array<{ document_id?: number; external_label?: string; page?: number; field_paths?: string[] }> | null; preview: { impact?: string } | null }
interface ChangeSet { id: string; status: string; operation_count: number; created_at: string | null; review_note: string | null; operations: Operation[] }
function Status({ value }: { value: string }) {
    const labels: Record<string, string> = { proposed: 'รอตรวจ', approved: 'อนุมัติแล้ว', applied: 'บันทึกแล้ว', rejected: 'ไม่อนุมัติ', conflicted: 'ข้อมูลเปลี่ยน', suspended: 'พักไว้' };
    const classes: Record<string, string> = { proposed: 'bg-amber-100 text-amber-800', approved: 'bg-blue-100 text-blue-800', applied: 'bg-emerald-100 text-emerald-800', rejected: 'bg-slate-100 text-slate-700', conflicted: 'bg-red-100 text-red-800', suspended: 'bg-violet-100 text-violet-800' };
    return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${classes[value] ?? classes.rejected}`}>{labels[value] ?? value}</span>;
}

function StatusNotice({ status }: { status: string }) {
    const content: Record<string, { className: string; message: string; applied?: boolean }> = {
        proposed: { className: 'border-amber-200 bg-amber-50 text-amber-950', message: 'ชุดนี้ยังเป็นข้อเสนอ ตรวจข้อมูล แหล่งอ้างอิง และผลกระทบก่อนกด “อนุมัติ”' },
        approved: { className: 'border-amber-200 bg-amber-50 text-amber-950', message: 'อนุมัติยังไม่บันทึกข้อมูล กด “ใช้งานชุดนี้” จึงจะบันทึกแบบ all-or-none ระบบตรวจเวอร์ชันซ้ำก่อนเสมอ และข้อมูลที่สร้างใหม่จะยังไม่เผยแพร่' },
        applied: { className: 'border-emerald-200 bg-emerald-50 text-emerald-950', message: 'บันทึกชุดนี้แล้ว รายการที่สร้างใหม่ยังเป็น draft และยังไม่ปรากฏในช่องทางลูกค้า', applied: true },
        conflicted: { className: 'border-red-200 bg-red-50 text-red-950', message: 'ชุดนี้ไม่ได้ถูกบันทึก เพราะข้อมูลหรือเวอร์ชันเปลี่ยนระหว่างตรวจสอบ ให้สร้างข้อเสนอใหม่จากข้อมูลล่าสุด' },
        suspended: { className: 'border-violet-200 bg-violet-50 text-violet-950', message: 'ชุดนี้ถูกพักไว้ เพราะคีย์ต้นทางถูกเพิกถอน ต้องตรวจสอบความน่าเชื่อถือก่อนดำเนินการต่อ' },
        rejected: { className: 'border-slate-200 bg-slate-50 text-slate-800', message: 'ชุดนี้ถูกปฏิเสธ จึงไม่มีข้อมูลธุรกิจถูกแก้ไข' },
    };
    const notice = content[status] ?? content.rejected;
    const Icon = notice.applied ? CheckCircle2 : ShieldAlert;
    return <section className={`rounded-lg border p-4 text-sm ${notice.className}`}><div className="flex gap-2"><Icon className="mt-0.5 h-5 w-5 shrink-0" /><p>{notice.message}</p></div></section>;
}

export default function AgentChangesShow({ changeSet }: { changeSet: ChangeSet }) {
    const canApprove = changeSet.status === 'proposed';
    const canApply = changeSet.status === 'approved';
    function post(path: string) { router.post(path, {}, { preserveScroll: true }); }
    function reject() { const note = window.prompt('บันทึกเหตุผล (ไม่บังคับ)'); if (note !== null) router.post(routes.agentChanges.reject(changeSet.id), { review_note: note }, { preserveScroll: true }); }
    return <AdminLayout title="ตรวจข้อเสนอ AI" actions={<Link href={routes.agentChanges.index}><Button variant="secondary"><ArrowLeft className="h-4 w-4" /> กลับ</Button></Link>}>
        <Head title="ตรวจข้อเสนอ AI" />
        <div className="mx-auto max-w-5xl space-y-6">
            <Card className="p-6"><div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><div className="flex items-center gap-2"><ClipboardCheck className="h-5 w-5 text-zinc-700" /><h2 className="text-xl font-semibold text-slate-900">ชุดการเปลี่ยนแปลง</h2><Status value={changeSet.status} /></div><p className="mt-2 break-all font-mono text-xs text-slate-500">{changeSet.id}</p><p className="mt-2 text-sm text-slate-600">{changeSet.operation_count} รายการ · ส่งเมื่อ {changeSet.created_at ? new Date(changeSet.created_at).toLocaleString('th-TH') : '—'}</p></div><div className="flex flex-wrap gap-2">{canApprove && <Button onClick={() => post(routes.agentChanges.approve(changeSet.id))}><Check className="h-4 w-4" /> อนุมัติ</Button>}{canApply && <Button onClick={() => post(routes.agentChanges.apply(changeSet.id))}><Check className="h-4 w-4" /> ใช้งานชุดนี้</Button>}{(canApprove || canApply) && <Button variant="secondary" onClick={reject}><X className="h-4 w-4" /> ไม่อนุมัติ</Button>}</div></div>{changeSet.review_note && <div className="mt-5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">หมายเหตุ: {changeSet.review_note}</div>}</Card>
            <StatusNotice status={changeSet.status} />
            {changeSet.operations.map((op) => <Card key={op.sequence} className="overflow-hidden"><div className="flex flex-col gap-2 border-b border-slate-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><span className="mr-2 text-xs font-semibold text-slate-500">#{op.sequence}</span><span className="font-semibold text-slate-900">{op.action} {op.entity}</span>{op.target_id && <span className="ml-2 text-xs text-slate-500">record #{op.target_id} · expected v{op.expected_version}</span>}</div><span className="text-xs text-slate-500">{op.preview?.impact ?? 'ต้องตรวจสอบก่อนใช้งาน'}</span></div><div className="grid gap-5 p-5 lg:grid-cols-[1fr_280px]"><div><h3 className="mb-2 text-sm font-semibold text-slate-800">ข้อมูลที่ agent เสนอ</h3><pre className="max-h-96 overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-6 text-zinc-100">{JSON.stringify(op.payload, null, 2)}</pre></div><aside><h3 className="mb-2 text-sm font-semibold text-slate-800">แหล่งอ้างอิง</h3>{op.sources && op.sources.length > 0 ? <ul className="space-y-2 text-sm text-slate-600">{op.sources.map((source, i) => <li key={i} className="rounded-lg border border-slate-200 p-3"><FileText className="mb-1 h-4 w-4 text-slate-400" />{source.document_id ? `เอกสารภายใน #${source.document_id}` : source.external_label}{source.page ? ` · หน้า ${source.page}` : ''}</li>)}</ul> : <p className="text-sm text-slate-500">ไม่มีแหล่งอ้างอิงแนบมา</p>}</aside></div></Card>)}
        </div>
    </AdminLayout>;
}
