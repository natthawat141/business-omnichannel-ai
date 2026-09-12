import { Head, Link, router } from "@inertiajs/react";
import { useMemo, useState } from "react";
import {
    ArrowRight,
    ClipboardCheck,
    FileWarning,
    ShieldCheck,
} from "lucide-react";
import AdminLayout from "@/components/AdminLayout";
import { Button, Card } from "@/components/ui";
import { routes } from "@/lib/routes";

interface ChangeSummary {
    id: string;
    status: string;
    operation_count: number;
    created_at: string | null;
    reviewed_at: string | null;
    operations: Array<{ entity: string; action: string }>;
}

interface Props {
    changeSets: {
        data: ChangeSummary[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filter: string;
}

const labels: Record<string, string> = {
    proposed: "รอตรวจ",
    approved: "อนุมัติแล้ว",
    applied: "บันทึกแล้ว",
    rejected: "ไม่อนุมัติ",
    conflicted: "ข้อมูลเปลี่ยน",
    suspended: "พักไว้",
};

function statusClass(status: string): string {
    return (
        {
            proposed: "bg-amber-100 text-amber-800",
            approved: "bg-blue-100 text-blue-800",
            applied: "bg-emerald-100 text-emerald-800",
            rejected: "bg-slate-100 text-slate-700",
            conflicted: "bg-red-100 text-red-800",
            suspended: "bg-violet-100 text-violet-800",
        }[status] ?? "bg-slate-100 text-slate-700"
    );
}

export default function AgentChangesIndex({ changeSets, filter }: Props) {
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const approvedIds = useMemo(
        () =>
            changeSets.data
                .filter((set) => set.status === "approved")
                .map((set) => set.id),
        [changeSets.data],
    );
    const selectedApprovedIds = selectedIds.filter((id) =>
        approvedIds.includes(id),
    );
    const allApprovedSelected =
        approvedIds.length > 0 &&
        selectedApprovedIds.length === approvedIds.length;

    function toggleSelection(id: string, checked: boolean) {
        setSelectedIds((current) =>
            checked
                ? [...new Set([...current, id])]
                : current.filter((currentId) => currentId !== id),
        );
    }

    function selectAllApproved(checked: boolean) {
        setSelectedIds(checked ? approvedIds : []);
    }

    function applySelected() {
        const count = selectedApprovedIds.length;
        if (count === 0) return;
        if (
            !window.confirm(
                `ใช้งานข้อเสนอที่อนุมัติแล้ว ${count} ชุดหรือไม่?\n\nระบบตรวจข้อมูลอีกครั้งและบันทึกทั้งหมดในธุรกรรมเดียว หากมีชุดใดขัดแย้ง จะไม่บันทึกข้อมูลธุรกิจของชุดอื่น รายการใหม่ยังเป็น draft`,
            )
        )
            return;
        router.post(
            routes.agentChanges.bulkApply,
            { change_sets: selectedApprovedIds },
            { preserveScroll: true },
        );
    }

    return (
        <AdminLayout title="ตรวจข้อเสนอ AI">
            <Head title="ตรวจข้อเสนอ AI" />
            <div className="min-w-0 space-y-6">
                <section className="rounded-lg border border-zinc-200 bg-zinc-950 px-6 py-7 text-white">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-zinc-300">
                                <ShieldCheck className="h-4 w-4" /> Human review
                                required
                            </div>
                            <h2 className="text-2xl font-semibold">
                                ข้อเสนอจาก AI ยังไม่ใช่ข้อมูลจริง
                            </h2>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-zinc-300">
                                ตรวจ diff, แหล่งอ้างอิง และผลกระทบก่อนอนุมัติ
                                เมื่ออนุมัติแล้ว
                                คุณเลือกหลายชุดที่ตรวจครบแล้วเพื่อใช้งานพร้อมกันได้
                                โดยรายการที่สร้างใหม่จะเป็น draft เสมอ
                            </p>
                        </div>
                        <Link
                            href="/admin/ai-setup"
                            className="inline-flex shrink-0 self-start items-center whitespace-nowrap gap-2 rounded-lg border border-zinc-600 px-3 py-2 text-sm hover:bg-white/10"
                        >
                            จัดการ Agent key <ArrowRight className="h-4 w-4" />
                        </Link>
                    </div>
                </section>

                <div className="flex flex-wrap gap-2" aria-label="กรองสถานะ">
                    {[
                        ["", "ทั้งหมด"],
                        ["proposed", "รอตรวจ"],
                        ["approved", "อนุมัติแล้ว"],
                        ["conflicted", "ข้อมูลเปลี่ยน"],
                        ["suspended", "พักไว้"],
                        ["applied", "บันทึกแล้ว"],
                    ].map(([value, label]) => (
                        <Link
                            key={value}
                            href={
                                value
                                    ? `${routes.agentChanges.index}?status=${value}`
                                    : routes.agentChanges.index
                            }
                            className={`rounded-full px-3 py-1.5 text-sm ${filter === value ? "bg-zinc-900 text-white" : "bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50"}`}
                        >
                            {label}
                        </Link>
                    ))}
                </div>

                {approvedIds.length > 0 && (
                    <section className="flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="font-semibold text-slate-900">
                                ใช้งานหลายชุด
                            </h3>
                            <p className="mt-1 text-sm text-slate-600">
                                เลือกเฉพาะข้อเสนอที่คุณอนุมัติแล้วในหน้านี้
                                จากนั้นยืนยันเพียงครั้งเดียว
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    selectAllApproved(!allApprovedSelected)
                                }
                            >
                                {allApprovedSelected
                                    ? "ยกเลิกการเลือก"
                                    : `เลือกทั้งหมด ${approvedIds.length} ชุด`}
                            </Button>
                            <Button
                                type="button"
                                disabled={selectedApprovedIds.length === 0}
                                onClick={applySelected}
                            >
                                ใช้งาน {selectedApprovedIds.length} ชุดที่เลือก
                            </Button>
                        </div>
                    </section>
                )}

                {changeSets.data.length === 0 ? (
                    <Card className="p-10 text-center">
                        <ClipboardCheck className="mx-auto h-8 w-8 text-slate-400" />
                        <h3 className="mt-3 font-semibold text-slate-900">
                            ยังไม่มีข้อเสนอ
                        </h3>
                        <p className="mt-1 text-sm text-slate-500">
                            เมื่อ agent ส่งชุดการเปลี่ยนแปลงเข้ามา
                            จะปรากฏที่นี่ก่อนข้อมูลธุรกิจถูกแก้ไข
                        </p>
                    </Card>
                ) : (
                    <Card className="min-w-0 overflow-hidden">
                        <div
                            className="overflow-x-auto"
                            role="region"
                            aria-label="รายการข้อเสนอ AI เลื่อนแนวนอนเพื่อดูทุกคอลัมน์"
                            tabIndex={0}
                        >
                            <table className="w-full min-w-[880px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-medium text-slate-500">
                                    <tr>
                                        <th className="w-12 px-5 py-3">
                                            <input
                                                aria-label="เลือกข้อเสนอที่อนุมัติทั้งหมดในหน้านี้"
                                                type="checkbox"
                                                checked={allApprovedSelected}
                                                disabled={
                                                    approvedIds.length === 0
                                                }
                                                onChange={(event) =>
                                                    selectAllApproved(
                                                        event.target.checked,
                                                    )
                                                }
                                                className="h-4 w-4 rounded border-slate-300 accent-zinc-900 focus:ring-zinc-400 disabled:cursor-not-allowed"
                                            />
                                        </th>
                                        <th className="px-5 py-3">ข้อเสนอ</th>
                                        <th className="px-5 py-3">
                                            การเปลี่ยนแปลง
                                        </th>
                                        <th className="px-5 py-3">สถานะ</th>
                                        <th className="px-5 py-3">ส่งเมื่อ</th>
                                        <th className="px-5 py-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {changeSets.data.map((set) => (
                                        <tr
                                            key={set.id}
                                            className="border-t border-slate-100"
                                        >
                                            <td className="px-5 py-4">
                                                {set.status === "approved" ? (
                                                    <input
                                                        aria-label={`เลือกข้อเสนอ ${set.id}`}
                                                        type="checkbox"
                                                        checked={selectedApprovedIds.includes(
                                                            set.id,
                                                        )}
                                                        onChange={(event) =>
                                                            toggleSelection(
                                                                set.id,
                                                                event.target
                                                                    .checked,
                                                            )
                                                        }
                                                        className="h-4 w-4 rounded border-slate-300 accent-zinc-900 focus:ring-zinc-400"
                                                    />
                                                ) : (
                                                    <span
                                                        aria-hidden="true"
                                                        className="text-slate-300"
                                                    >
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="max-w-[240px] break-all px-5 py-4 font-mono text-xs text-slate-700">
                                                {set.id}
                                            </td>
                                            <td className="px-5 py-4">
                                                <div className="font-medium text-slate-900">
                                                    {set.operation_count} รายการ
                                                </div>
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {set.operations
                                                        .map(
                                                            (op) =>
                                                                `${op.action} ${op.entity}`,
                                                        )
                                                        .join(" · ")}
                                                </div>
                                            </td>
                                            <td className="px-5 py-4">
                                                <span
                                                    className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${statusClass(set.status)}`}
                                                >
                                                    {labels[set.status] ??
                                                        set.status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-4 text-slate-500">
                                                {set.created_at
                                                    ? new Date(
                                                          set.created_at,
                                                      ).toLocaleString("th-TH")
                                                    : "—"}
                                            </td>
                                            <td className="px-5 py-4">
                                                <Link
                                                    className="inline-flex min-h-10 items-center whitespace-nowrap gap-1 text-sm font-medium text-zinc-800 underline underline-offset-4"
                                                    href={routes.agentChanges.show(
                                                        set.id,
                                                    )}
                                                >
                                                    ตรวจสอบ{" "}
                                                    <ArrowRight className="h-4 w-4" />
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}
                <p className="flex items-center gap-2 text-xs text-slate-500">
                    <FileWarning className="h-4 w-4" />{" "}
                    หากข้อมูลเปลี่ยนระหว่างรอ ระบบจะไม่บันทึกบางส่วน
                    และจะให้แก้ข้อขัดแย้งก่อน
                </p>
            </div>
        </AdminLayout>
    );
}
