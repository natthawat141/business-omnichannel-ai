import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, ShieldCheck, Upload, XCircle } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { Button, EmptyState, Table, Td, Th } from '@/components/ui';
import { routes } from '@/lib/routes';

interface PreviewRow {
    row: number;
    code: string;
    name_th: string;
    status: 'new' | 'duplicate' | 'invalid';
    reason: string;
}

interface Preview {
    token: string;
    filename: string;
    new_count: number;
    duplicate_count: number;
    invalid_count: number;
    rows: PreviewRow[];
}

interface HistoryRow {
    id: number;
    filename: string;
    status: 'completed' | 'failed';
    rows_imported: number;
    rows_failed: number;
    rows_skipped: number;
    created_at: string | null;
}

interface Props {
    columns: string[];
    history: HistoryRow[];
    preview: Preview | null;
}

const fields = [
    ['code', 'จำเป็น', 'รหัสทรัพย์ไม่ซ้ำ เช่น CONDO-2026-001'],
    ['category_slug', 'แนะนำ', 'ต้องตรงกับ Slug ที่มีอยู่ในหน้าประเภททรัพย์ เช่น condo, house, land'],
    ['transaction_type', 'แนะนำ', 'sale, rent หรือ service'],
    ['availability', 'ไม่จำเป็น', 'available = พร้อมให้ AI นำเสนอ; ค่าอื่นคือยังไม่พร้อม/ปิดรายการ'],
    ['name_th', 'จำเป็น', 'ชื่อรายการทรัพย์'],
    ['description_th', 'ไม่จำเป็น', 'รายละเอียดที่ต้องการให้ AI ใช้ตอบ'],
    ['price', 'ไม่จำเป็น', 'ราคาปกติ เป็นตัวเลข ไม่ใส่ comma'],
    ['sale_price', 'ไม่จำเป็น', 'ราคาโปรโมชัน เป็นตัวเลข'],
    ['location_text', 'แนะนำ', 'ย่านหรือทำเลที่ลูกค้าใช้ค้นหา'],
    ['province', 'ไม่จำเป็น', 'จังหวัด'],
    ['district', 'ไม่จำเป็น', 'เขตหรืออำเภอ'],
    ['subdistrict', 'ไม่จำเป็น', 'แขวงหรือตำบล'],
    ['project_name', 'ไม่จำเป็น', 'ชื่อโครงการ'],
    ['bedrooms', 'ไม่จำเป็น', 'จำนวนห้องนอน'],
    ['bathrooms', 'ไม่จำเป็น', 'จำนวนห้องน้ำ'],
    ['usable_area_sqm', 'ไม่จำเป็น', 'พื้นที่ใช้สอย ตร.ม.'],
    ['land_area_sqw', 'ไม่จำเป็น', 'พื้นที่ดิน ตร.ว.'],
    ['floor', 'ไม่จำเป็น', 'ชั้น'],
    ['primary_image_url', 'แนะนำ', 'URL HTTPS ของรูปหลักสำหรับ Flex Card'],
    ['effective_from', 'ไม่จำเป็น', 'วันเริ่มใช้ รูปแบบ YYYY-MM-DD'],
    ['effective_until', 'ไม่จำเป็น', 'วันสิ้นสุด รูปแบบ YYYY-MM-DD'],
    ['terms', 'ไม่จำเป็น', 'เงื่อนไขและข้อจำกัด'],
    ['keywords', 'ไม่จำเป็น', 'คำค้น คั่นด้วย comma'],
] as const;

function Status({ status }: { status: PreviewRow['status'] }) {
    const styles = {
        new: 'bg-zinc-100 text-zinc-800',
        duplicate: 'bg-amber-100 text-amber-800',
        invalid: 'bg-red-100 text-red-800',
    };
    const labels = { new: 'เพิ่มใหม่', duplicate: 'รหัสซ้ำ—ข้าม', invalid: 'ข้อมูลผิด—ข้าม' };

    return <span className={`inline-flex rounded-full px-2 py-1 text-xs font-medium ${styles[status]}`}>{labels[status]}</span>;
}

export default function ImportsIndex({ columns, history, preview }: Props) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{ file: File | null }>({ file: null });

    function inspect(e: FormEvent) {
        e.preventDefault();
        form.post(routes.imports.preview, { forceFormData: true, preserveScroll: true });
    }

    function confirmImport() {
        const message = `ยืนยันเพิ่ม ${preview?.new_count ?? 0} รายการเป็นฉบับร่าง?\n\nระบบจะไม่เขียนทับรหัสเดิม และ AI จะยังไม่ใช้ข้อมูลจนกว่าจะเปิดใช้งาน เปิดเผยแพร่ อยู่ในช่วงวันที่มีผล และมีสถานะพร้อมเสนอ`;
        if (preview && window.confirm(message)) {
            router.post(routes.imports.confirm, { token: preview.token }, { preserveScroll: true });
        }
    }

    function cancelPreview() {
        if (preview) router.post(routes.imports.cancel, { token: preview.token }, { preserveScroll: true });
    }

    return (
        <AdminLayout title="นำเข้ารายการทรัพย์">
            <Head title="นำเข้ารายการทรัพย์" />

            <div className="space-y-6">
                <section className="rounded-xl border border-amber-300 bg-amber-50 p-5">
                    <div className="flex items-start gap-3">
                        <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />
                        <div>
                            <h2 className="font-semibold text-amber-950">ระบบนี้เพิ่มข้อมูลใหม่เท่านั้น ไม่มีการเขียนทับ</h2>
                            <p className="mt-1 text-sm leading-6 text-amber-900">
                                นำเข้าได้ทั้งรายการทรัพย์และไฟล์ legacy 9 คอลัมน์ หากพบ <strong>code ซ้ำ</strong> ระบบจะข้ามรายการนั้น
                                และข้อมูลใหม่จะถูกเก็บเป็น <strong>ฉบับร่าง</strong> ก่อน ต้องเปิดใช้งาน เผยแพร่ อยู่ในช่วงวันที่มีผล
                                และมีสถานะพร้อมเสนอ จึงจะถูกส่งให้ AI ใช้ตอบ
                            </p>
                        </div>
                    </div>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:p-6">
                    <div className="flex flex-col gap-4">
                        <div>
                            <div className="flex items-center gap-2">
                                <FileSpreadsheet className="h-5 w-5 text-zinc-700" />
                                <h2 className="text-lg font-semibold text-slate-900">1. เตรียมไฟล์ Excel หรือ CSV</h2>
                            </div>
                            <p className="mt-1 text-sm text-slate-600">ใช้หัวคอลัมน์ตามลำดับที่กำหนด ห้ามเปลี่ยนชื่อหรือสลับคอลัมน์</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <a href={routes.imports.template}><Button type="button" variant="secondary"><Download className="h-4 w-4" />ไฟล์เปล่า</Button></a>
                            <a href={routes.imports.exportUrl}><Button type="button" variant="secondary"><Download className="h-4 w-4" />ส่งออกรายการทรัพย์</Button></a>
                        </div>
                    </div>

                    <div className="mt-5 overflow-x-auto rounded-lg border border-slate-200">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50"><tr><Th>คอลัมน์</Th><Th>ต้องกรอก</Th><Th>ใช้เก็บอะไร</Th></tr></thead>
                            <tbody className="divide-y divide-slate-100">
                                {fields.filter(([name]) => columns.includes(name)).map(([name, required, description]) => (
                                    <tr key={name}><Td><code className="rounded bg-slate-100 px-1.5 py-1 text-xs">{name}</code></Td><Td>{required}</Td><Td>{description}</Td></tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:p-6">
                    <h2 className="text-lg font-semibold text-slate-900">2. เลือกไฟล์และตรวจสอบก่อนเพิ่ม</h2>
                    <p className="mt-1 text-sm text-slate-600">ขั้นตอนนี้ยังไม่บันทึกข้อมูลลงฐานข้อมูล</p>
                    <form onSubmit={inspect} className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                        <input
                            ref={input}
                            type="file"
                            accept=".xlsx,.xls,.csv"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            className="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-zinc-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-800"
                        />
                        <Button type="submit" disabled={!form.data.file || form.processing} className="shrink-0">
                            <Upload className="h-4 w-4" />{form.processing ? 'กำลังตรวจสอบ...' : 'ตรวจสอบไฟล์'}
                        </Button>
                    </form>
                    {form.errors.file && <p className="mt-2 text-sm text-red-600">{form.errors.file}</p>}
                </section>

                {preview && (
                    <section className="rounded-xl border-2 border-zinc-300 bg-white p-5 shadow-sm lg:p-6">
                        <div className="flex items-start gap-3">
                            <ShieldCheck className="mt-0.5 h-6 w-6 shrink-0 text-zinc-700" />
                            <div>
                                <h2 className="text-lg font-semibold text-slate-900">3. ตรวจผลและยืนยันการเพิ่ม</h2>
                                <p className="mt-1 text-sm text-slate-600">ไฟล์: {preview.filename}</p>
                            </div>
                        </div>

                        <div className="mt-5 grid gap-3 sm:grid-cols-3">
                            <div className="rounded-lg border border-zinc-200 bg-zinc-50 p-4"><p className="text-sm text-zinc-800">พร้อมเพิ่มใหม่</p><p className="mt-1 text-2xl font-bold text-zinc-900">{preview.new_count}</p></div>
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4"><p className="text-sm text-amber-800">รหัสซ้ำ—ข้าม</p><p className="mt-1 text-2xl font-bold text-amber-900">{preview.duplicate_count}</p></div>
                            <div className="rounded-lg border border-red-200 bg-red-50 p-4"><p className="text-sm text-red-800">ข้อมูลผิด—ข้าม</p><p className="mt-1 text-2xl font-bold text-red-900">{preview.invalid_count}</p></div>
                        </div>

                        <div className="mt-5"><Table><thead className="bg-slate-50"><tr><Th>แถว</Th><Th>code</Th><Th>ชื่อ</Th><Th>ผลตรวจ</Th><Th>เหตุผล</Th></tr></thead><tbody className="divide-y divide-slate-100">
                            {preview.rows.map((row) => <tr key={`${row.row}-${row.code}`}><Td>{row.row}</Td><Td><code>{row.code || '—'}</code></Td><Td>{row.name_th || '—'}</Td><Td><Status status={row.status} /></Td><Td>{row.reason}</Td></tr>)}
                        </tbody></Table></div>

                        <div className="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                            <strong>ตรวจสอบก่อนกดยืนยัน:</strong> ระบบจะเพิ่มเฉพาะ {preview.new_count} รายการสีเขียวเป็นฉบับร่าง ส่วนรหัสซ้ำและข้อมูลผิดจะไม่ถูกบันทึก
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button type="button" onClick={confirmImport} disabled={preview.new_count === 0}><CheckCircle2 className="h-4 w-4" />ยืนยันเพิ่ม {preview.new_count} รายการ</Button>
                            <Button type="button" variant="secondary" onClick={cancelPreview}><XCircle className="h-4 w-4" />ยกเลิก</Button>
                        </div>
                    </section>
                )}

                <section>
                    <h2 className="mb-3 text-base font-semibold text-slate-900">ประวัติการนำเข้ารายการทรัพย์</h2>
                    {history.length === 0 ? <div className="rounded-xl border border-slate-200 bg-white"><EmptyState message="ยังไม่มีประวัติการนำเข้า" /></div> : (
                        <Table><thead className="bg-slate-50"><tr><Th>ไฟล์</Th><Th>เพิ่มใหม่</Th><Th>ข้ามรหัสซ้ำ</Th><Th>ผิดพลาด</Th><Th>วันที่</Th></tr></thead><tbody className="divide-y divide-slate-100">
                            {history.map((row) => <tr key={row.id}><Td>{row.filename}</Td><Td className="text-zinc-700">{row.rows_imported}</Td><Td className="text-amber-700">{row.rows_skipped}</Td><Td className="text-red-700">{row.rows_failed}</Td><Td>{row.created_at ?? '—'}</Td></tr>)}
                        </tbody></Table>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
