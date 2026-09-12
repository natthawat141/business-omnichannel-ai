import { Head, Link } from '@inertiajs/react';
import { Activity, BookOpen, Bot, CheckCircle2, HelpCircle, MapPin, MessageSquareText, Package } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import { Card } from '@/components/ui';
import { routes } from '@/lib/routes';

interface Stats {
    packages: number;
    publishedPackages: number;
    faqs: number;
    knowledge: number;
}

interface Interaction {
    id: number;
    question: string;
    answer: string;
    response_type: 'ai' | 'location' | 'fallback' | 'non_text';
    status: 'answered' | 'failed';
    model: string | null;
    duration_ms: number | null;
    created_at: string | null;
}

interface Analytics {
    total: number;
    today: number;
    successRate: number;
    latest: Interaction[];
}

const tiles = [
    { key: 'packages', label: 'รายการทรัพย์', icon: Package, href: routes.packages.index },
    { key: 'publishedPackages', label: 'ทรัพย์ที่เผยแพร่', icon: CheckCircle2, href: routes.packages.index },
    { key: 'faqs', label: 'คำถามพบบ่อย', icon: HelpCircle, href: routes.faqs.index },
    { key: 'knowledge', label: 'คลังความรู้', icon: BookOpen, href: routes.knowledge.index },
] as const;

const responseTypeLabels: Record<Interaction['response_type'], string> = {
    ai: 'AI',
    location: 'พิกัด',
    fallback: 'ข้อความสำรอง',
    non_text: 'ไม่ใช่ข้อความ',
};

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat('th-TH', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}

export default function Dashboard({ stats, analytics }: { stats: Stats; analytics: Analytics }) {
    return (
        <AdminLayout title="แดชบอร์ด">
            <Head title="แดชบอร์ด" />
            <div className="mb-6 max-w-2xl">
                <h2 className="text-xl font-semibold text-slate-800">กำหนดข้อมูลที่ AI ใช้ตอบ</h2>
                <p className="mt-1 text-sm text-slate-500">เพิ่มหรือแก้ไขข้อมูลด้านล่าง แล้วเปิดสถานะใช้งานเมื่อพร้อมให้ AI นำไปตอบลูกค้า</p>
            </div>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                {tiles.map((tile) => {
                    const Icon = tile.icon;
                    return (
                        <Link key={tile.key} href={tile.href}>
                            <Card className="p-4 transition hover:border-zinc-300 hover:shadow-md">
                                <Icon className="h-6 w-6 text-zinc-600" />
                                <p className="mt-3 text-2xl font-bold text-slate-800">{stats[tile.key]}</p>
                                <p className="text-xs text-slate-500">{tile.label}</p>
                            </Card>
                        </Link>
                    );
                })}
            </div>

            <section className="mt-8 overflow-hidden rounded-xl border border-slate-200 bg-white">
                <div className="flex flex-col gap-2 border-b border-slate-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <Activity className="h-5 w-5 text-zinc-700" />
                            <h2 className="text-lg font-semibold text-slate-900">การตอบของ AI</h2>
                        </div>
                        <p className="mt-1 text-sm text-slate-600">ดูคำถามและคำตอบล่าสุดที่ส่งผ่าน LINE Bot</p>
                    </div>
                    <p className="text-xs text-slate-500">ไม่จัดเก็บ LINE User ID จริง</p>
                </div>

                <div className="grid divide-y divide-slate-200 border-b border-slate-200 bg-slate-50 sm:grid-cols-3 sm:divide-x sm:divide-y-0">
                    <div className="px-5 py-4">
                        <div className="flex items-center gap-2 text-sm text-slate-600">
                            <MessageSquareText className="h-4 w-4" /> วันนี้
                        </div>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{analytics.today}</p>
                    </div>
                    <div className="px-5 py-4">
                        <div className="flex items-center gap-2 text-sm text-slate-600">
                            <Bot className="h-4 w-4" /> ทั้งหมด
                        </div>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{analytics.total}</p>
                    </div>
                    <div className="px-5 py-4">
                        <div className="flex items-center gap-2 text-sm text-slate-600">
                            <CheckCircle2 className="h-4 w-4" /> ตอบสำเร็จ
                        </div>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">{analytics.successRate}%</p>
                    </div>
                </div>

                {analytics.latest.length === 0 ? (
                    <div className="px-5 py-12 text-center">
                        <MessageSquareText className="mx-auto h-7 w-7 text-slate-400" />
                        <p className="mt-3 font-medium text-slate-800">ยังไม่มีประวัติการตอบ</p>
                        <p className="mt-1 text-sm text-slate-500">เมื่อมีข้อความใหม่จาก LINE คำถามและคำตอบจะแสดงที่นี่</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-white text-xs font-semibold text-slate-500">
                                <tr className="border-b border-slate-200">
                                    <th className="whitespace-nowrap px-5 py-3">เวลา</th>
                                    <th className="min-w-64 px-5 py-3">ลูกค้าถาม</th>
                                    <th className="min-w-80 px-5 py-3">Bot ตอบ</th>
                                    <th className="whitespace-nowrap px-5 py-3">ประเภท</th>
                                    <th className="whitespace-nowrap px-5 py-3">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {analytics.latest.map((interaction) => (
                                    <tr key={interaction.id} className="align-top hover:bg-slate-50">
                                        <td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500">{formatDate(interaction.created_at)}</td>
                                        <td className="px-5 py-4 leading-6 text-slate-800">{interaction.question}</td>
                                        <td className="px-5 py-4 leading-6 text-slate-600">{interaction.answer}</td>
                                        <td className="px-5 py-4">
                                            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">
                                                {interaction.response_type === 'location' && <MapPin className="h-3.5 w-3.5" />}
                                                {responseTypeLabels[interaction.response_type]}
                                            </span>
                                        </td>
                                        <td className="px-5 py-4">
                                            <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-medium ${
                                                interaction.status === 'answered'
                                                    ? 'bg-zinc-100 text-zinc-800'
                                                    : 'bg-red-100 text-red-800'
                                            }`}>
                                                {interaction.status === 'answered' ? 'สำเร็จ' : 'ไม่สำเร็จ'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </AdminLayout>
    );
}
