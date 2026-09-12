import { Head, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { Plus, Pencil } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import Pagination from '@/components/Pagination';
import SearchBar from '@/components/SearchBar';
import DeleteButton from '@/components/DeleteButton';
import { Badge, Button, SelectInput, Table, Th, Td, EmptyState } from '@/components/ui';
import { routes } from '@/lib/routes';
import type { PackageCategory, Paginated } from '@/types';

interface Props {
    categories: Paginated<PackageCategory>;
    filters: { search: string; is_active: string | null };
}

export default function CategoriesIndex({ categories, filters }: Props) {
    const canEdit = usePage<PageProps>().props.auth.user?.can_edit;
    return (
        <AdminLayout
            title="ประเภททรัพย์"
            actions={canEdit &&
                <Link href={routes.categories.create}>
                    <Button>
                        <Plus className="h-4 w-4" />
                        เพิ่มประเภททรัพย์
                    </Button>
                </Link>
            }
        >
            <Head title="ประเภททรัพย์" />

            <div className="mb-4">
                <SearchBar action={routes.categories.index} initial={filters.search} placeholder="ค้นหาประเภททรัพย์">
                    <SelectInput
                        defaultValue={filters.is_active ?? ''}
                        name="is_active"
                        onChange={(e) => {
                            const value = e.target.value;
                            const url = new URL(window.location.href);
                            if (value) url.searchParams.set('is_active', value);
                            else url.searchParams.delete('is_active');
                            window.location.href = url.toString();
                        }}
                        className="sm:w-40"
                    >
                        <option value="">ทุกสถานะ</option>
                        <option value="1">ใช้งาน</option>
                        <option value="0">ปิด</option>
                    </SelectInput>
                </SearchBar>
            </div>

            {categories.data.length === 0 ? (
                <div className="rounded-xl border border-slate-200 bg-white">
                    <EmptyState message="ยังไม่มีข้อมูลประเภททรัพย์" />
                </div>
            ) : (
                <Table>
                    <thead className="bg-slate-50">
                        <tr>
                            <Th>ชื่อประเภททรัพย์</Th>
                            <Th>slug</Th>
                            <Th>จำนวนรายการทรัพย์</Th>
                            <Th>ลำดับ</Th>
                            <Th>สถานะ</Th>
                            <Th className="text-right">จัดการ</Th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {categories.data.map((category) => (
                            <tr key={category.id} className="hover:bg-slate-50">
                                <Td>
                                    <div className="font-medium text-slate-800">{category.name_th}</div>
                                    {category.name_en && <div className="text-xs text-slate-500">{category.name_en}</div>}
                                </Td>
                                <Td>
                                    <span className="text-slate-600">{category.slug}</span>
                                </Td>
                                <Td>{category.packages_count ?? 0}</Td>
                                <Td>{category.sort_order}</Td>
                                <Td>
                                    <Badge active={category.is_active} />
                                </Td>
                                <Td className="text-right">
                                    <div className={canEdit ? 'flex items-center justify-end gap-1' : 'hidden'}>
                                        <Link
                                            href={routes.categories.edit(category.id)}
                                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100"
                                        >
                                            <Pencil className="h-4 w-4" />
                                        </Link>
                                        <DeleteButton url={routes.categories.destroy(category.id)} />
                                    </div>
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}

            <Pagination paginator={categories} />
        </AdminLayout>
    );
}
