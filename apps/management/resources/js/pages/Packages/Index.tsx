import { Head, Link, usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';
import { Bath, BedDouble, Building2, ImageOff, MapPin, Pencil, Plus, Ruler } from 'lucide-react';
import AdminLayout from '@/components/AdminLayout';
import Pagination from '@/components/Pagination';
import SearchBar from '@/components/SearchBar';
import DeleteButton from '@/components/DeleteButton';
import { Badge, Button, Card, EmptyState, SelectInput } from '@/components/ui';
import { routes } from '@/lib/routes';
import { formatBaht } from '@/lib/format';
import type { ServicePackage, Paginated } from '@/types';

interface Props {
    packages: Paginated<ServicePackage>;
    categories: { id: number; name_th: string }[];
    filters: { search: string; category_id: string | null; is_published: string | null; is_active: string | null };
}

function applyFilter(name: string, value: string) {
    const url = new URL(window.location.href);
    if (value) url.searchParams.set(name, value);
    else url.searchParams.delete(name);
    window.location.href = url.toString();
}

function propertyType(pkg: ServicePackage) {
    if (pkg.transaction_type === 'sale') return 'ขาย';
    if (pkg.transaction_type === 'rent') return 'เช่า';
    if (pkg.transaction_type === 'service') return 'บริการ';

    return 'รายการทรัพย์';
}

function propertyLocation(pkg: ServicePackage) {
    return [pkg.location_text, pkg.district, pkg.province].filter((value, index, values) => value && values.indexOf(value) === index).join(' · ');
}

export default function PackagesIndex({ packages, categories, filters }: Props) {
    const canEdit = usePage<PageProps>().props.auth.user?.can_edit;
    return (
        <AdminLayout
            title="รายการทรัพย์"
            actions={canEdit &&
                <Link href={routes.packages.create}>
                    <Button>
                        <Plus className="h-4 w-4" />
                        เพิ่มทรัพย์
                    </Button>
                </Link>
            }
        >
            <Head title="รายการทรัพย์" />

            <div className="mb-4">
                <SearchBar action={routes.packages.index} initial={filters.search} placeholder="ค้นหาชื่อ / รหัส / คีย์เวิร์ด">
                    <SelectInput
                        defaultValue={filters.category_id ?? ''}
                        name="category_id"
                        onChange={(e) => applyFilter('category_id', e.target.value)}
                        className="sm:w-44"
                    >
                        <option value="">ทุกประเภททรัพย์</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name_th}
                            </option>
                        ))}
                    </SelectInput>
                    <SelectInput
                        defaultValue={filters.is_published ?? ''}
                        name="is_published"
                        onChange={(e) => applyFilter('is_published', e.target.value)}
                        className="sm:w-40"
                    >
                        <option value="">ทั้งหมด</option>
                        <option value="1">เผยแพร่</option>
                        <option value="0">ฉบับร่าง</option>
                    </SelectInput>
                </SearchBar>
            </div>

            {packages.data.length === 0 ? (
                <div className="rounded-xl border border-slate-200 bg-white">
                    <EmptyState message="ยังไม่มีรายการทรัพย์" />
                </div>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {packages.data.map((pkg) => {
                        const location = propertyLocation(pkg);

                        return (
                            <Card key={pkg.id} className="group flex min-w-0 flex-col overflow-hidden transition hover:border-slate-300 hover:shadow-md">
                                <div className="relative aspect-[16/9] overflow-hidden bg-slate-100">
                                    <div className="absolute inset-0 grid place-items-center text-slate-400">
                                        <ImageOff className="h-8 w-8" aria-hidden="true" />
                                    </div>
                                    {pkg.primary_image_url && (
                                        <img
                                            src={pkg.primary_image_url}
                                            alt={`รูป ${pkg.name_th}`}
                                            className="relative h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                            onError={(event) => { event.currentTarget.style.display = 'none'; }}
                                        />
                                    )}
                                    <div className="absolute top-3 left-3 flex flex-wrap gap-1.5">
                                        <span className="rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                                            {propertyType(pkg)}
                                        </span>
                                        {!pkg.is_published && (
                                            <span className="rounded-full bg-amber-100/95 px-2.5 py-1 text-xs font-semibold text-amber-800 shadow-sm">
                                                ฉบับร่าง
                                            </span>
                                        )}
                                    </div>
                                </div>

                                <div className="flex flex-1 flex-col p-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-xs font-medium text-slate-500">{pkg.category?.name_th ?? 'ไม่ระบุประเภท'}</p>
                                            <h2 className="mt-1 truncate text-base font-semibold text-slate-900" title={pkg.name_th}>{pkg.name_th}</h2>
                                            {pkg.code && <p className="mt-0.5 truncate text-xs text-slate-500">{pkg.code}</p>}
                                        </div>
                                        <Building2 className="mt-1 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                    </div>

                                    {location ? (
                                        <p className="mt-3 flex min-h-5 items-start gap-1.5 text-sm text-slate-600">
                                            <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                                            <span className="line-clamp-1">{location}</span>
                                        </p>
                                    ) : (
                                        <div className="mt-3 min-h-5" />
                                    )}

                                    <div className="mt-4 flex min-h-5 flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                        {pkg.bedrooms !== null && <span className="inline-flex items-center gap-1"><BedDouble className="h-3.5 w-3.5" />{pkg.bedrooms} นอน</span>}
                                        {pkg.bathrooms !== null && <span className="inline-flex items-center gap-1"><Bath className="h-3.5 w-3.5" />{pkg.bathrooms} น้ำ</span>}
                                        {pkg.usable_area_sqm !== null && <span className="inline-flex items-center gap-1"><Ruler className="h-3.5 w-3.5" />{pkg.usable_area_sqm.toLocaleString('th-TH')} ตร.ม.</span>}
                                    </div>

                                    <div className="mt-5 flex items-end justify-between gap-3 border-t border-slate-100 pt-3">
                                        <div className="min-w-0">
                                            {pkg.sale_price !== null ? (
                                                <>
                                                    <p className="font-semibold text-slate-900">{formatBaht(pkg.sale_price)}</p>
                                                    {pkg.price !== null && <p className="text-xs text-slate-400 line-through">{formatBaht(pkg.price)}</p>}
                                                </>
                                            ) : (
                                                <p className="font-semibold text-slate-900">{formatBaht(pkg.price)}</p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <Badge active={pkg.is_published} labels={['เผยแพร่', 'ร่าง']} />
                                            {!pkg.is_active && <Badge active={false} labels={['ใช้งาน', 'ปิด']} />}
                                        </div>
                                    </div>

                                    {canEdit && (
                                        <div className="mt-3 flex items-center justify-end gap-1 border-t border-slate-100 pt-2">
                                            <Link
                                                href={routes.packages.edit(pkg.id)}
                                                className="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                                            >
                                                <Pencil className="h-4 w-4" />
                                                แก้ไข
                                            </Link>
                                            <DeleteButton url={routes.packages.destroy(pkg.id)} />
                                        </div>
                                    )}
                                </div>
                            </Card>
                        );
                    })}
                </div>
            )}

            <Pagination paginator={packages} />
        </AdminLayout>
    );
}
