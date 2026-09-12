import { Head, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { Plus, Pencil } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import Pagination from '@/components/Pagination';
import SearchBar from '@/components/SearchBar';
import DeleteButton from '@/components/DeleteButton';
import { Badge, Button, SelectInput, Table, Th, Td, EmptyState } from '@/components/ui';
import { truncate } from '@/lib/format';
import { routes } from '@/lib/routes';
import type { Faq, Paginated } from '@/types';

interface Props {
    faqs: Paginated<Faq>;
    categories: string[];
    filters: { search: string; category: string | null; is_active: string | null };
}

export default function FaqsIndex({ faqs, categories, filters }: Props) {
    const canEdit = usePage<PageProps>().props.auth.user?.can_edit;
    return (
        <AdminLayout
            title="คำถามที่พบบ่อย"
            actions={canEdit &&
                <Link href={routes.faqs.create}>
                    <Button>
                        <Plus className="h-4 w-4" />
                        เพิ่มคำถาม
                    </Button>
                </Link>
            }
        >
            <Head title="คำถามที่พบบ่อย" />

            <div className="mb-4">
                <SearchBar action={routes.faqs.index} initial={filters.search} placeholder="ค้นหาคำถาม / คำตอบ">
                    <SelectInput
                        defaultValue={filters.category ?? ''}
                        name="category"
                        onChange={(e) => {
                            const category = e.target.value;
                            const url = new URL(window.location.href);
                            if (category) url.searchParams.set('category', category);
                            else url.searchParams.delete('category');
                            window.location.href = url.toString();
                        }}
                        className="sm:w-48"
                    >
                        <option value="">ทุกหมวด</option>
                        {categories.map((category) => (
                            <option key={category} value={category}>
                                {category}
                            </option>
                        ))}
                    </SelectInput>
                </SearchBar>
            </div>

            {faqs.data.length === 0 ? (
                <div className="rounded-xl border border-slate-200 bg-white">
                    <EmptyState message="ยังไม่มีข้อมูลคำถาม" />
                </div>
            ) : (
                <Table>
                    <thead className="bg-slate-50">
                        <tr>
                            <Th>คำถาม</Th>
                            <Th>หมวด</Th>
                            <Th>แท็ก</Th>
                            <Th>สถานะ</Th>
                            <Th className="text-right">จัดการ</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {faqs.data.map((faq) => (
                            <tr key={faq.id} className="hover:bg-slate-50">
                                <Td>
                                    <div className="font-medium text-slate-800">{truncate(faq.question_th)}</div>
                                </Td>
                                <Td>{faq.category ?? '—'}</Td>
                                <Td>{faq.tags ?? '—'}</Td>
                                <Td>
                                    <Badge active={faq.is_active} />
                                </Td>
                                <Td className="text-right">
                                    <div className={canEdit ? 'flex items-center justify-end gap-1' : 'hidden'}>
                                        <Link
                                            href={routes.faqs.edit(faq.id)}
                                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </Link>
                                        <DeleteButton url={routes.faqs.destroy(faq.id)} />
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}

            <Pagination paginator={faqs} />
        </AdminLayout>
    );
}
