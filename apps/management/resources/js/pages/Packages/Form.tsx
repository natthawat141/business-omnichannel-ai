import { Head, Link, useForm } from "@inertiajs/react";
import {
    ArrowLeft,
    ExternalLink,
    ImagePlus,
    LoaderCircle,
    Save,
    Trash2,
} from "lucide-react";
import { useState } from "react";
import type { ChangeEvent, FormEvent } from "react";
import AdminLayout from "@/components/AdminLayout";
import CatalogProfileFields from "@/components/CatalogProfileFields";
import type { CatalogProfileSchema } from "@/components/CatalogProfileFields";
import {
    Button,
    Card,
    Field,
    SelectInput,
    TextArea,
    TextInput,
    Toggle,
} from "@/components/ui";
import { routes } from "@/lib/routes";
import type { ServicePackage } from "@/types";

interface Props {
    pkg: ServicePackage | null;
    categories: { id: number; name_th: string }[];
    profileSchemas: Record<string, CatalogProfileSchema>;
}

interface DirectUploadResponse {
    data?: {
        upload_url?: string;
        delivery_url?: string;
        method?: "POST" | "PUT";
        headers?: Record<string, string>;
    };
    message?: string;
}

const PRIMARY_IMAGE_TYPES = new Set(["image/jpeg", "image/png", "image/webp"]);
const PRIMARY_IMAGE_MAX_BYTES = 10 * 1024 * 1024;

function isAvailability(
    value: string,
): value is ServicePackage["availability"] {
    return [
        "available",
        "reserved",
        "sold",
        "rented",
        "unavailable",
        "unknown",
    ].includes(value);
}

export default function PackageForm({
    pkg,
    categories,
    profileSchemas,
}: Props) {
    const editing = Boolean(pkg);
    const [imageUploading, setImageUploading] = useState(false);
    const [imageUploadError, setImageUploadError] = useState<string | null>(
        null,
    );
    const [imageUploadMessage, setImageUploadMessage] = useState<string | null>(
        null,
    );
    const { data, setData, post, put, processing, errors } = useForm({
        category_id: pkg?.category_id ?? null,
        item_type: pkg?.item_type ?? "property",
        record_kind: pkg?.record_kind ?? "offer",
        profile: pkg?.profile ?? "",
        profile_data: pkg?.profile_data ?? {},
        parent_id: pkg?.parent_id ?? null,
        lock_version: pkg?.lock_version ?? null,
        code: pkg?.code ?? "",
        name_th: pkg?.name_th ?? "",
        name_en: pkg?.name_en ?? "",
        description_th: pkg?.description_th ?? "",
        description_en: pkg?.description_en ?? "",
        price: pkg?.price ?? "",
        sale_price: pkg?.sale_price ?? "",
        currency: pkg?.currency ?? "THB",
        transaction_type: pkg?.transaction_type ?? "",
        availability: pkg?.availability ?? "available",
        duration_minutes: pkg?.duration_minutes ?? "",
        terms: pkg?.terms ?? "",
        keywords: pkg?.keywords ?? "",
        location_text: pkg?.location_text ?? "",
        province: pkg?.province ?? "",
        district: pkg?.district ?? "",
        subdistrict: pkg?.subdistrict ?? "",
        project_name: pkg?.project_name ?? "",
        primary_image_url: pkg?.primary_image_url ?? "",
        map_url: pkg?.map_url ?? "",
        bedrooms: pkg?.bedrooms ?? "",
        bathrooms: pkg?.bathrooms ?? "",
        usable_area_sqm: pkg?.usable_area_sqm ?? "",
        land_area_sqw: pkg?.land_area_sqw ?? "",
        floor: pkg?.floor ?? "",
        attributes: pkg?.attributes ? JSON.stringify(pkg.attributes) : "",
        effective_from: pkg?.effective_from ?? "",
        effective_until: pkg?.effective_until ?? "",
        is_active: pkg?.is_active ?? true,
        is_published: pkg?.is_published ?? false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        if (processing || imageUploading) return;
        if (editing && pkg) {
            put(routes.packages.update(pkg.id));
        } else {
            post(routes.packages.store);
        }
    }

    async function uploadPrimaryImage(event: ChangeEvent<HTMLInputElement>) {
        const input = event.currentTarget;
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        setImageUploadError(null);
        setImageUploadMessage(null);

        if (!PRIMARY_IMAGE_TYPES.has(file.type)) {
            setImageUploadError("รองรับเฉพาะไฟล์ JPEG, PNG และ WebP");
            input.value = "";
            return;
        }

        if (file.size > PRIMARY_IMAGE_MAX_BYTES) {
            setImageUploadError("ไฟล์ต้องมีขนาดไม่เกิน 10 MB");
            input.value = "";
            return;
        }

        const csrfToken = document.querySelector<HTMLMetaElement>(
            'meta[name="csrf-token"]',
        )?.content;
        if (!csrfToken) {
            setImageUploadError(
                "ไม่พบโทเคนความปลอดภัย กรุณารีเฟรชหน้าแล้วลองใหม่",
            );
            input.value = "";
            return;
        }

        setImageUploading(true);

        try {
            const directUploadResponse = await fetch(
                routes.propertyImages.directUpload,
                {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "Content-Type": "application/json",
                        "X-CSRF-TOKEN": csrfToken,
                    },
                    body: JSON.stringify({ content_type: file.type }),
                },
            );
            const directUpload = (await directUploadResponse
                .json()
                .catch(() => null)) as DirectUploadResponse | null;
            const uploadUrl = directUpload?.data?.upload_url;
            const deliveryUrl = directUpload?.data?.delivery_url;
            const uploadMethod = directUpload?.data?.method ?? "POST";
            const uploadHeaders = directUpload?.data?.headers ?? {};

            if (!directUploadResponse.ok || !uploadUrl || !deliveryUrl) {
                throw new Error(
                    directUpload?.message || "เริ่มอัปโหลดรูปไม่สำเร็จ",
                );
            }

            const body =
                uploadMethod === "PUT"
                    ? file
                    : (() => {
                          const form = new FormData();
                          form.append("file", file, file.name);
                          return form;
                      })();
            const cloudflareResponse = await fetch(uploadUrl, {
                method: uploadMethod,
                headers: uploadMethod === "PUT" ? uploadHeaders : undefined,
                body,
            });

            if (!cloudflareResponse.ok) {
                throw new Error(
                    "ที่เก็บรูปไม่รับไฟล์นี้ กรุณาตรวจชนิดและขนาดไฟล์",
                );
            }

            setData("primary_image_url", deliveryUrl);
            setImageUploadMessage(
                "อัปโหลดสำเร็จ กรุณาบันทึกรายการทรัพย์เพื่อใช้รูปนี้",
            );
        } catch (error) {
            setImageUploadError(
                error instanceof Error ? error.message : "อัปโหลดรูปไม่สำเร็จ",
            );
        } finally {
            setImageUploading(false);
            input.value = "";
        }
    }

    return (
        <AdminLayout
            title={editing ? "แก้ไขรายการทรัพย์" : "เพิ่มรายการทรัพย์"}
        >
            <Head title={editing ? "แก้ไขรายการทรัพย์" : "เพิ่มรายการทรัพย์"} />

            <Link
                href={routes.packages.index}
                className="mb-4 inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-700"
            >
                <ArrowLeft className="h-4 w-4" />
                กลับ
            </Link>

            <form onSubmit={submit} className="mx-auto min-w-0 max-w-5xl">
                <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="break-words text-xl font-semibold text-slate-900">
                            {editing ? pkg?.name_th : "รายการทรัพย์ใหม่"}
                        </h1>
                        <p className="mt-1 text-sm text-slate-600">
                            รูปภาพและรายละเอียดที่ทีมงานใช้ดูแลรายการนี้
                        </p>
                    </div>
                    <span className="inline-flex shrink-0 whitespace-nowrap rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">
                        {data.is_published ? "เผยแพร่" : "ฉบับร่าง"}
                    </span>
                </div>
                {Object.keys(errors).length > 0 && (
                    <div
                        role="alert"
                        className="mb-5 rounded-lg border border-red-300 bg-white p-4 text-sm text-red-700"
                    >
                        <p className="font-semibold">
                            ยังบันทึกไม่ได้ กรุณาตรวจข้อมูลต่อไปนี้
                        </p>
                        <ul className="mt-2 list-inside list-disc">
                            {Object.entries(errors).map(([key, message]) => (
                                <li key={key}>{message}</li>
                            ))}
                        </ul>
                    </div>
                )}
                <Card className="min-w-0 px-4 py-5 sm:px-7 sm:py-7">
                    <section
                        id="images"
                        aria-labelledby="images-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="images-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            รูปภาพ
                        </h2>

                        <Field
                            label="รูปหลักทรัพย์"
                            error={
                                errors.primary_image_url ||
                                imageUploadError ||
                                undefined
                            }
                        >
                            <div className="grid items-center gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                                <div className="flex aspect-[16/10] min-w-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                                    {data.primary_image_url ? (
                                        <img
                                            src={data.primary_image_url}
                                            alt="ตัวอย่างรูปหลักทรัพย์"
                                            className="h-full w-full object-contain"
                                        />
                                    ) : (
                                        <div className="p-6 text-center text-slate-600">
                                            <ImagePlus className="mx-auto mb-3 h-8 w-8" />
                                            <p className="text-sm">
                                                ยังไม่มีรูปหลัก
                                            </p>
                                        </div>
                                    )}
                                </div>
                                <div className="min-w-0 space-y-4">
                                    <p className="text-sm leading-6 text-slate-600">
                                        เลือกรูปที่ต้องการแสดงบนการ์ดรายการ
                                        การเปลี่ยนรูปจะมีผลเมื่อบันทึกข้อมูล
                                    </p>
                                    <label className="inline-flex min-h-10 cursor-pointer items-center justify-center gap-2 rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-zinc-50 focus-within:ring-2 focus-within:ring-zinc-400 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60">
                                        {imageUploading ? (
                                            <LoaderCircle className="h-4 w-4 animate-spin" />
                                        ) : (
                                            <ImagePlus className="h-4 w-4" />
                                        )}
                                        {imageUploading
                                            ? "กำลังอัปโหลด…"
                                            : data.primary_image_url
                                              ? "เปลี่ยนรูปจากเครื่อง"
                                              : "เลือกรูปจากเครื่อง"}
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            className="sr-only"
                                            disabled={
                                                imageUploading || processing
                                            }
                                            onChange={uploadPrimaryImage}
                                        />
                                    </label>
                                    {data.primary_image_url && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            disabled={
                                                imageUploading || processing
                                            }
                                            onClick={() => {
                                                setData(
                                                    "primary_image_url",
                                                    "",
                                                );
                                                setImageUploadError(null);
                                                setImageUploadMessage(null);
                                            }}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                            เอารูปออก
                                        </Button>
                                    )}
                                    {imageUploadMessage && (
                                        <p
                                            className="text-sm text-emerald-700"
                                            aria-live="polite"
                                        >
                                            {imageUploadMessage}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </Field>
                    </section>
                    <section
                        id="identity"
                        aria-labelledby="identity-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="identity-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            ข้อมูลรายการ
                        </h2>

                        <div>
                            <Field
                                label="ประเภททรัพย์"
                                error={errors.category_id}
                            >
                                <SelectInput
                                    aria-label="ประเภททรัพย์"
                                    value={data.category_id ?? ""}
                                    onChange={(e) =>
                                        setData(
                                            "category_id",
                                            e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        )
                                    }
                                    error={errors.category_id}
                                >
                                    <option value="">— ไม่ระบุ —</option>
                                    {categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name_th}
                                        </option>
                                    ))}
                                </SelectInput>
                            </Field>
                        </div>

                        <Field label="รหัสทรัพย์" error={errors.code}>
                            <TextInput
                                aria-label="รหัสทรัพย์"
                                value={data.code}
                                onChange={(e) =>
                                    setData("code", e.target.value)
                                }
                                error={errors.code}
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="ชื่อทรัพย์ (ไทย)"
                                error={errors.name_th}
                                required
                            >
                                <TextInput
                                    aria-label="ชื่อทรัพย์ (ไทย)"
                                    value={data.name_th}
                                    onChange={(e) =>
                                        setData("name_th", e.target.value)
                                    }
                                    error={errors.name_th}
                                />
                            </Field>
                            <Field
                                label="ชื่อทรัพย์ (อังกฤษ)"
                                error={errors.name_en}
                            >
                                <TextInput
                                    aria-label="ชื่อทรัพย์ (อังกฤษ)"
                                    value={data.name_en}
                                    onChange={(e) =>
                                        setData("name_en", e.target.value)
                                    }
                                    error={errors.name_en}
                                />
                            </Field>
                        </div>

                        <Field
                            label="รายละเอียด (ไทย)"
                            error={errors.description_th}
                        >
                            <TextArea
                                aria-label="รายละเอียด (ไทย)"
                                rows={3}
                                value={data.description_th}
                                onChange={(e) =>
                                    setData("description_th", e.target.value)
                                }
                                error={errors.description_th}
                            />
                        </Field>
                        <Field
                            label="รายละเอียด (อังกฤษ)"
                            error={errors.description_en}
                        >
                            <TextArea
                                aria-label="รายละเอียด (อังกฤษ)"
                                rows={3}
                                value={data.description_en}
                                onChange={(e) =>
                                    setData("description_en", e.target.value)
                                }
                                error={errors.description_en}
                            />
                        </Field>
                    </section>
                    <section
                        id="pricing"
                        aria-labelledby="pricing-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="pricing-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            ราคาและเงื่อนไข
                        </h2>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="ราคา" error={errors.price}>
                                <TextInput
                                    aria-label="ราคา"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.price}
                                    onChange={(e) =>
                                        setData("price", e.target.value)
                                    }
                                    error={errors.price}
                                />
                            </Field>
                            <Field
                                label="ราคาโปรโมชัน"
                                error={errors.sale_price}
                            >
                                <TextInput
                                    aria-label="ราคาโปรโมชัน"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.sale_price}
                                    onChange={(e) =>
                                        setData("sale_price", e.target.value)
                                    }
                                    error={errors.sale_price}
                                />
                            </Field>
                            <Field label="สกุลเงิน" error={errors.currency}>
                                <TextInput
                                    aria-label="สกุลเงิน"
                                    value={data.currency}
                                    onChange={(e) =>
                                        setData("currency", e.target.value)
                                    }
                                    error={errors.currency}
                                />
                            </Field>
                            <Field
                                label="ขาย / เช่า / บริการ"
                                error={errors.transaction_type}
                            >
                                <SelectInput
                                    aria-label="ขาย / เช่า / บริการ"
                                    value={data.transaction_type}
                                    onChange={(e) =>
                                        setData(
                                            "transaction_type",
                                            e.target.value,
                                        )
                                    }
                                    error={errors.transaction_type}
                                >
                                    <option value="">— ไม่ระบุ —</option>
                                    <option value="sale">ขาย</option>
                                    <option value="rent">เช่า</option>
                                    <option value="service">บริการ</option>
                                </SelectInput>
                            </Field>
                            <Field
                                label="ความพร้อม"
                                error={errors.availability}
                            >
                                <SelectInput
                                    aria-label="ความพร้อม"
                                    value={data.availability}
                                    onChange={(e) => {
                                        if (isAvailability(e.target.value)) {
                                            setData(
                                                "availability",
                                                e.target.value,
                                            );
                                        }
                                    }}
                                    error={errors.availability}
                                >
                                    <option value="unknown">
                                        ยังไม่ทราบ / ไม่ใช่ยูนิตจริง
                                    </option>
                                    <option value="available">พร้อมเสนอ</option>
                                    <option value="reserved">จองแล้ว</option>
                                    <option value="sold">ขายแล้ว</option>
                                    <option value="rented">
                                        ปล่อยเช่าแล้ว
                                    </option>
                                    <option value="unavailable">
                                        ไม่พร้อม
                                    </option>
                                </SelectInput>
                            </Field>
                        </div>

                        <Field label="เงื่อนไข" error={errors.terms}>
                            <TextArea
                                aria-label="เงื่อนไข"
                                rows={3}
                                value={data.terms}
                                onChange={(e) =>
                                    setData("terms", e.target.value)
                                }
                                error={errors.terms}
                            />
                        </Field>
                        <Field
                            label="คีย์เวิร์ด"
                            error={errors.keywords}
                            hint="คั่นด้วยจุลภาคเพื่อช่วยการค้นหา"
                        >
                            <TextInput
                                aria-label="คีย์เวิร์ด"
                                value={data.keywords}
                                onChange={(e) =>
                                    setData("keywords", e.target.value)
                                }
                                error={errors.keywords}
                            />
                        </Field>
                    </section>
                    <section
                        id="location"
                        aria-labelledby="location-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="location-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            ทำเลและแผนที่
                        </h2>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field
                                label="พื้นที่/ทำเล"
                                error={errors.location_text}
                            >
                                <TextInput
                                    aria-label="พื้นที่/ทำเล"
                                    value={data.location_text}
                                    onChange={(e) =>
                                        setData("location_text", e.target.value)
                                    }
                                    error={errors.location_text}
                                />
                            </Field>
                            <Field label="จังหวัด" error={errors.province}>
                                <TextInput
                                    aria-label="จังหวัด"
                                    value={data.province}
                                    onChange={(e) =>
                                        setData("province", e.target.value)
                                    }
                                    error={errors.province}
                                />
                            </Field>
                            <Field label="เขต/อำเภอ" error={errors.district}>
                                <TextInput
                                    aria-label="เขต/อำเภอ"
                                    value={data.district}
                                    onChange={(e) =>
                                        setData("district", e.target.value)
                                    }
                                    error={errors.district}
                                />
                            </Field>
                            <Field label="แขวง/ตำบล" error={errors.subdistrict}>
                                <TextInput
                                    aria-label="แขวง/ตำบล"
                                    value={data.subdistrict}
                                    onChange={(e) =>
                                        setData("subdistrict", e.target.value)
                                    }
                                    error={errors.subdistrict}
                                />
                            </Field>
                            <Field label="โครงการ" error={errors.project_name}>
                                <TextInput
                                    aria-label="โครงการ"
                                    value={data.project_name}
                                    onChange={(e) =>
                                        setData("project_name", e.target.value)
                                    }
                                    error={errors.project_name}
                                />
                            </Field>
                        </div>

                        <Field
                            label="ลิงก์ Google Maps"
                            error={errors.map_url}
                            hint="วางลิงก์จากปุ่มแชร์ใน Google Maps หรือแผนที่อื่นที่ใช้ HTTPS"
                        >
                            <TextInput
                                aria-label="ลิงก์ Google Maps"
                                type="url"
                                maxLength={2048}
                                placeholder="https://maps.app.goo.gl/…"
                                value={data.map_url}
                                onChange={(e) =>
                                    setData("map_url", e.target.value)
                                }
                                error={errors.map_url}
                            />
                        </Field>
                        {data.map_url.startsWith("https://") && (
                            <a
                                href={data.map_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex min-h-10 items-center gap-2 text-sm text-slate-700 underline underline-offset-4"
                            >
                                เปิดแผนที่เพื่อตรวจสอบ{" "}
                                <ExternalLink className="h-4 w-4" />
                            </a>
                        )}
                    </section>
                    <section
                        id="specifications"
                        aria-labelledby="specifications-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="specifications-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            รายละเอียดพื้นที่
                        </h2>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Field label="ห้องนอน" error={errors.bedrooms}>
                                <TextInput
                                    aria-label="ห้องนอน"
                                    type="number"
                                    min={0}
                                    value={data.bedrooms}
                                    onChange={(e) =>
                                        setData("bedrooms", e.target.value)
                                    }
                                    error={errors.bedrooms}
                                />
                            </Field>
                            <Field label="ห้องน้ำ" error={errors.bathrooms}>
                                <TextInput
                                    aria-label="ห้องน้ำ"
                                    type="number"
                                    min={0}
                                    value={data.bathrooms}
                                    onChange={(e) =>
                                        setData("bathrooms", e.target.value)
                                    }
                                    error={errors.bathrooms}
                                />
                            </Field>
                            <Field label="ชั้น" error={errors.floor}>
                                <TextInput
                                    aria-label="ชั้น"
                                    type="number"
                                    min={0}
                                    value={data.floor}
                                    onChange={(e) =>
                                        setData("floor", e.target.value)
                                    }
                                    error={errors.floor}
                                />
                            </Field>
                            <Field
                                label="พื้นที่ใช้สอย (ตร.ม.)"
                                error={errors.usable_area_sqm}
                            >
                                <TextInput
                                    aria-label="พื้นที่ใช้สอย (ตร.ม.)"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.usable_area_sqm}
                                    onChange={(e) =>
                                        setData(
                                            "usable_area_sqm",
                                            e.target.value,
                                        )
                                    }
                                    error={errors.usable_area_sqm}
                                />
                            </Field>
                            <Field
                                label="พื้นที่ดิน (ตร.ว.)"
                                error={errors.land_area_sqw}
                            >
                                <TextInput
                                    aria-label="พื้นที่ดิน (ตร.ว.)"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.land_area_sqw}
                                    onChange={(e) =>
                                        setData("land_area_sqw", e.target.value)
                                    }
                                    error={errors.land_area_sqw}
                                />
                            </Field>
                        </div>
                    </section>
                    <details
                        className="border-t border-slate-200 py-7"
                        open={Boolean(
                            data.profile ||
                            Object.keys(errors).some((key) =>
                                /^(profile|profile_data|parent_id|record_kind|attributes)(\.|$)/.test(
                                    key,
                                ),
                            ),
                        )}
                    >
                        <summary className="cursor-pointer text-lg font-semibold text-slate-900">
                            โครงสร้างข้อมูลและคุณสมบัติเพิ่มเติม
                        </summary>
                        <div className="mt-5 space-y-5">
                            {" "}
                            <Field
                                label="โปรไฟล์ข้อมูล"
                                error={errors.profile}
                                hint="เพิ่มโครงสร้างเฉพาะธุรกิจ โดยยังใช้หมวดหมู่เดิมได้"
                            >
                                <SelectInput
                                    aria-label="โปรไฟล์ข้อมูล"
                                    value={data.profile}
                                    onChange={(e) => {
                                        const profile = e.target.value;
                                        setData((previous) => ({
                                            ...previous,
                                            profile,
                                            record_kind:
                                                profileSchemas[profile]
                                                    ?.record_kind ??
                                                previous.record_kind,
                                            availability: profile
                                                ? "unknown"
                                                : previous.availability,
                                        }));
                                    }}
                                >
                                    <option value="">
                                        ทั่วไป — ไม่ใช้โปรไฟล์
                                    </option>
                                    {Object.entries(profileSchemas).map(
                                        ([name, schema]) => (
                                            <option key={name} value={name}>
                                                {schema.label}
                                            </option>
                                        ),
                                    )}
                                </SelectInput>
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="ระดับข้อมูล"
                                    error={errors.record_kind}
                                >
                                    <SelectInput
                                        aria-label="ระดับข้อมูล"
                                        value={data.record_kind}
                                        disabled={Boolean(data.profile)}
                                        onChange={(e) => {
                                            const kind = e.target.value;
                                            if (
                                                kind === "group" ||
                                                kind === "variant" ||
                                                kind === "offer"
                                            )
                                                setData("record_kind", kind);
                                        }}
                                    >
                                        <option value="group">
                                            กลุ่ม / โครงการ
                                        </option>
                                        <option value="variant">
                                            รูปแบบ / แบบห้อง
                                        </option>
                                        <option value="offer">
                                            รายการขายหรือเช่าจริง
                                        </option>
                                    </SelectInput>
                                </Field>
                                <Field
                                    label="รหัสรายการแม่ (ID)"
                                    error={errors.parent_id}
                                    hint="แบบห้องอยู่ใต้โครงการ; ยูนิตอยู่ใต้โครงการหรือแบบห้อง"
                                >
                                    <TextInput
                                        aria-label="รหัสรายการแม่"
                                        type="number"
                                        min={1}
                                        value={data.parent_id ?? ""}
                                        onChange={(e) =>
                                            setData(
                                                "parent_id",
                                                e.target.value
                                                    ? Number(e.target.value)
                                                    : null,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                            {data.profile && profileSchemas[data.profile] && (
                                <>
                                    <CatalogProfileFields
                                        schema={profileSchemas[data.profile]}
                                        value={data.profile_data}
                                        onChange={(value) =>
                                            setData("profile_data", value)
                                        }
                                        errors={errors}
                                    />
                                    <p className="text-sm text-slate-500">
                                        โครงการและแบบห้องไม่ใช่ยูนิตว่าง:
                                        เว้นราคาไว้
                                        และเพิ่มราคากับสถานะว่างในรายการขายหรือเช่าจริง
                                    </p>
                                </>
                            )}
                            {errors.profile_data && (
                                <p
                                    role="alert"
                                    className="text-sm text-red-600"
                                >
                                    {errors.profile_data}
                                </p>
                            )}
                            {Object.keys(data.profile_data).length > 0 && (
                                <button
                                    type="button"
                                    className="text-xs text-slate-500 underline"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                "ล้างข้อมูลโปรไฟล์ในฟอร์มนี้? ข้อมูลเดิมจะเปลี่ยนเมื่อกดบันทึกเท่านั้น",
                                            )
                                        )
                                            setData("profile_data", {});
                                    }}
                                >
                                    ล้างข้อมูลโปรไฟล์ก่อนเปลี่ยนชนิด
                                </button>
                            )}
                            <Field
                                label="คุณสมบัติเพิ่มเติม (JSON)"
                                error={errors.attributes}
                                hint='เช่น {"parking":"2 คัน","pet_friendly":"ได้"}'
                            >
                                <TextArea
                                    aria-label="คุณสมบัติเพิ่มเติม (JSON)"
                                    rows={3}
                                    value={data.attributes}
                                    onChange={(e) =>
                                        setData("attributes", e.target.value)
                                    }
                                    error={errors.attributes}
                                />
                            </Field>
                        </div>
                    </details>
                    <section
                        id="publication"
                        aria-labelledby="publication-title"
                        className="scroll-mt-6 space-y-5 border-t border-slate-200 py-7 first:border-t-0 first:pt-0"
                    >
                        <h2
                            id="publication-title"
                            className="text-lg font-semibold text-slate-900"
                        >
                            การเผยแพร่
                        </h2>
                        <p className="text-sm text-slate-600">
                            รายการที่ลูกค้าเห็นต้องเปิดใช้งาน เผยแพร่
                            อยู่ในช่วงวันที่มีผล และพร้อมเสนอ
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="เริ่มมีผล"
                                error={errors.effective_from}
                            >
                                <TextInput
                                    aria-label="เริ่มมีผล"
                                    type="date"
                                    value={data.effective_from}
                                    onChange={(e) =>
                                        setData(
                                            "effective_from",
                                            e.target.value,
                                        )
                                    }
                                    error={errors.effective_from}
                                />
                            </Field>
                            <Field
                                label="สิ้นสุดผล"
                                error={errors.effective_until}
                            >
                                <TextInput
                                    aria-label="สิ้นสุดผล"
                                    type="date"
                                    value={data.effective_until}
                                    onChange={(e) =>
                                        setData(
                                            "effective_until",
                                            e.target.value,
                                        )
                                    }
                                    error={errors.effective_until}
                                />
                            </Field>
                        </div>

                        <div className="flex flex-wrap gap-6">
                            <Field label="สถานะ" error={errors.is_active}>
                                <Toggle
                                    checked={data.is_active}
                                    onChange={(value) =>
                                        setData("is_active", value)
                                    }
                                    label="ใช้งาน"
                                />
                            </Field>
                            <Field
                                label="การเผยแพร่"
                                error={errors.is_published}
                            >
                                <Toggle
                                    checked={data.is_published}
                                    onChange={(value) =>
                                        setData("is_published", value)
                                    }
                                    label="เผยแพร่"
                                />
                            </Field>
                        </div>
                    </section>
                </Card>
                <div className="sticky bottom-0 z-10 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3">
                    <p className="text-sm text-slate-600">
                        {processing
                            ? "กำลังบันทึก…"
                            : imageUploading
                              ? "รออัปโหลดรูปให้เสร็จก่อนบันทึก"
                              : "ตรวจข้อมูลก่อนบันทึกการเปลี่ยนแปลง"}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={routes.packages.index}
                            className="inline-flex min-h-10 items-center rounded-md border border-slate-300 px-4 text-sm text-slate-700"
                        >
                            ยกเลิก
                        </Link>
                        <Button
                            type="submit"
                            disabled={processing || imageUploading}
                        >
                            <Save className="h-4 w-4" />
                            {editing ? "บันทึกการแก้ไข" : "เพิ่มทรัพย์"}
                        </Button>
                    </div>
                </div>
            </form>
        </AdminLayout>
    );
}
