import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Save } from 'lucide-react';
import type { FormEvent } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { Button, Card, Field, TextArea, TextInput, Toggle } from '@/components/ui';
import { routes } from '@/lib/routes';
import type { PackageCategory } from '@/types';

export default function CategoryForm({ category }: { category: PackageCategory | null }) {
    const editing = Boolean(category);
    const { data, setData, post, put, processing, errors } = useForm({
        name_th: category?.name_th ?? '',
        name_en: category?.name_en ?? '',
        slug: category?.slug ?? '',
        description: category?.description ?? '',
        sort_order: category?.sort_order ?? 0,
        is_active: category?.is_active ?? true,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (editing && category) {
            put(routes.categories.update(category.id));
        } else {
            post(routes.categories.store);
        }
    }

    return (
        <AdminLayout title={editing ? 'แก้ไขประเภททรัพย์' : 'เพิ่มประเภททรัพย์'}>
            <Head title={editing ? 'แก้ไขประเภททรัพย์' : 'เพิ่มประเภททรัพย์'} />

            <Link href={routes.categories.index} className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700">
                <ArrowLeft className="h-4 w-4" />
                กลับ
            </Link>

            <form onSubmit={submit}>
                <Card className="mx-auto max-w-2xl space-y-4 p-6">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="ชื่อประเภททรัพย์ (ไทย)" error={errors.name_th} required>
                            <TextInput value={data.name_th} onChange={(e) => setData('name_th', e.target.value)} error={errors.name_th} />
                        </Field>
                        <Field label="ชื่อประเภททรัพย์ (อังกฤษ)" error={errors.name_en}>
                            <TextInput value={data.name_en} onChange={(e) => setData('name_en', e.target.value)} error={errors.name_en} />
                        </Field>
                    </div>
                    <Field label="Slug" error={errors.slug} hint="เว้นว่างเพื่อสร้างอัตโนมัติ">
                        <TextInput value={data.slug} onChange={(e) => setData('slug', e.target.value)} error={errors.slug} />
                    </Field>
                    <Field label="รายละเอียด" error={errors.description}>
                        <TextArea rows={3} value={data.description} onChange={(e) => setData('description', e.target.value)} error={errors.description} />
                    </Field>
                    <Field label="ลำดับการแสดงผล" error={errors.sort_order}>
                        <TextInput
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', e.target.value === '' ? 0 : Number(e.target.value))}
                            error={errors.sort_order}
                        />
                    </Field>
                    <Field label="สถานะ" error={errors.is_active}>
                        <Toggle checked={data.is_active} onChange={(value) => setData('is_active', value)} label="ใช้งาน" />
                    </Field>

                    <div className="flex gap-2 pt-2">
                        <Button type="submit" disabled={processing}>
                            <Save className="h-4 w-4" />
                            {editing ? 'บันทึก' : 'เพิ่มประเภททรัพย์'}
                        </Button>
                        <Link href={routes.categories.index}>
                            <Button type="button" variant="secondary">
                                ยกเลิก
                            </Button>
                        </Link>
                    </div>
                </Card>
            </form>
        </AdminLayout>
    );
}
