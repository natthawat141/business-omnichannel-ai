import { Head, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { Plus, Pencil, LayoutGrid, List, BookOpen } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import Pagination from '@/components/Pagination';
import SearchBar from '@/components/SearchBar';
import DeleteButton from '@/components/DeleteButton';
import { Badge, Button, SelectInput, Table, Th, Td, EmptyState } from '@/components/ui';
import { routes } from '@/lib/routes';
import { formatDate } from '@/lib/format';
import type { KnowledgeEntry, Paginated } from '@/types';

interface Props {
    entries: Paginated<KnowledgeEntry>;
    types: string[];
    filters: { search: string; type: string | null; is_active: string | null; view: 'cards' | 'table' };
}

export default function KnowledgeIndex({ entries, types, filters }: Props) {
    const canEdit = usePage<PageProps>().props.auth.user?.can_edit;
    const view = filters.view ?? 'cards';
    const query = new URLSearchParams({ view });
    if (filters.type) query.set('type', filters.type);
    if (filters.is_active) query.set('is_active', filters.is_active);
    const searchAction = `${routes.knowledge.index}?${query}`;
    const viewHref = (next: string) => {
        const params = new URLSearchParams(query);
        params.set('view', next);
        if (filters.search) params.set('search', filters.search);
        return `${routes.knowledge.index}?${params}`;
    };
    return (
        <AdminLayout
            title="ความรู้"
            actions={canEdit &&
                <Link href={routes.knowledge.create}>
                    <Button>
                        <Plus className="h-4 w-4" />
                        เพิ่มความรู้
                    </Button>
                </Link>
            }
        >
            <Head title="ความรู้" />

            <div className="mb-4">
                <SearchBar action={searchAction} initial={filters.search} placeholder="ค้นหาหัวข้อ / เนื้อหา">
                    <SelectInput
                        defaultValue={filters.type ?? ''}
                        name="type"
                        onChange={(e) => {
                            const type = e.target.value;
                            const url = new URL(window.location.href);
                            url.searchParams.set('view', view);
                            url.searchParams.delete('page');
                            if (type) url.searchParams.set('type', type);
                            else url.searchParams.delete('type');
                            window.location.href = url.toString();
                        }}
                        className="sm:w-40"
                    >
                        <option value="">ทุกประเภท</option>
                        {types.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </SelectInput>
                </SearchBar>
            </div>

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-slate-600">{entries.data.length} รายการในหน้านี้</p>
                <div role="group" aria-label="มุมมองคลังความรู้" className="inline-flex rounded-lg border border-slate-200 bg-white p-1">
                    {([{ value: 'cards', label: 'การ์ด', Icon: LayoutGrid }, { value: 'table', label: 'ตาราง', Icon: List }] as const).map(({ value, label, Icon }) => (
                        <Link key={value} href={viewHref(value)} preserveScroll aria-current={view === value ? 'page' : undefined}
                            className={`inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 ${view === value ? 'bg-zinc-900 text-white' : 'text-slate-600 hover:bg-slate-100'}`}>
                            <Icon className="h-4 w-4" aria-hidden="true" />{label}
                        </Link>
                    ))}
                </div>
            </div>

            {entries.data.length === 0 ? (
                <div className="rounded-xl border border-slate-200 bg-white">
                    <EmptyState message="ยังไม่มีข้อมูลความรู้" />
                </div>
            ) : view === 'cards' ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {entries.data.map(entry => (
                        <article key={entry.id} className="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white p-5">
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <span className="inline-flex items-center gap-2 text-xs font-medium text-slate-600"><BookOpen className="h-4 w-4" aria-hidden="true" />{entry.type}</span>
                                <Badge active={entry.is_active} />
                            </div>
                            <h2 className="break-words text-base leading-7 font-semibold text-slate-900">{entry.title}</h2>
                            <p className="mt-2 line-clamp-4 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{entry.body || 'ยังไม่มีเนื้อหา'}</p>
                            <div className="mt-auto pt-5">
                                <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600"><span>{entry.category || 'ไม่ระบุหมวด'}</span><span>เวอร์ชัน {entry.version}</span></div>
                                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                                    <span className="text-xs text-slate-500">ตรวจล่าสุด {formatDate(entry.reviewed_at)}</span>
                                    {canEdit && <div className="flex items-center gap-1">
                                        <Link href={routes.knowledge.edit(entry.id)} aria-label={`แก้ไข ${entry.title}`} className="inline-flex items-center gap-1.5 rounded-md px-2 py-2 text-sm text-slate-700 hover:bg-slate-100"><Pencil className="h-4 w-4" aria-hidden="true" />แก้ไข</Link>
                                        <DeleteButton url={routes.knowledge.destroy(entry.id)} />
                                    </div>}
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            ) : (
                <Table>
                    <thead className="bg-slate-50">
                        <tr>
                            <Th>หัวข้อ</Th>
                            <Th>ประเภท</Th>
                            <Th>หมวด</Th>
                            <Th>เวอร์ชัน</Th>
                            <Th>ตรวจล่าสุด</Th>
                            <Th>สถานะ</Th>
                            <Th className="text-right">จัดการ</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {entries.data.map((entry) => (
                            <tr key={entry.id} className="hover:bg-slate-50">
                                <Td>
                                    <div className="font-medium text-slate-800">{entry.title}</div>
                                </Td>
                                <Td>
                                    <span className="text-slate-600">{entry.type}</span>
                                </Td>
                                <Td>{entry.category ?? '—'}</Td>
                                <Td>{entry.version}</Td>
                                <Td>{formatDate(entry.reviewed_at)}</Td>
                                <Td>
                                    <Badge active={entry.is_active} />
                                </Td>
                                <Td className="text-right">
                                    <div className={canEdit ? 'flex items-center justify-end gap-1' : 'hidden'}>
                                        <Link
                                            href={routes.knowledge.edit(entry.id)}
                                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </Link>
                                        <DeleteButton url={routes.knowledge.destroy(entry.id)} />
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}

            <Pagination paginator={entries} />
        </AdminLayout>
    );
}
