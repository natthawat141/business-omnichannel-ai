import { Field, SelectInput, TextInput } from '@/components/ui';
import type { ServicePackage } from '@/types';

export interface CatalogProfileSchema {
    version: number;
    label: string;
    record_kind: ServicePackage['record_kind'];
    fields: Record<string, {
        type: 'string' | 'number' | 'integer' | 'enum' | 'enum_list' | 'date' | 'month' | 'numeric_range';
        values?: string[]; min?: number; max?: number; max_length?: number; unit?: string;
    }>;
}

const labels: Record<string, string> = {
    developer: 'ผู้พัฒนา', operator: 'ผู้บริหารโครงการ', brand: 'แบรนด์', project_type: 'ประเภทโครงการ',
    latitude: 'ละติจูด', longitude: 'ลองจิจูด', construction_status: 'สถานะก่อสร้าง',
    construction_status_as_of: 'วันที่อ้างอิงสถานะ', expected_completion: 'กำหนดแล้วเสร็จตามเอกสาร',
    facilities: 'สิ่งอำนวยความสะดวก', building_count: 'จำนวนอาคาร', storey_count: 'จำนวนชั้น',
    unit_count: 'จำนวนยูนิตทั้งโครงการ', parking_spaces: 'จำนวนที่จอดรถ', passenger_lifts: 'ลิฟต์โดยสาร',
    service_lifts: 'ลิฟต์บริการ', document_reviewed_on: 'วันที่ตรวจเอกสาร', source_note: 'หมายเหตุแหล่งข้อมูล',
    layout_type: 'ประเภทแบบห้อง', area_range: 'ช่วงพื้นที่ (ตร.ม.)',
    high_rise_condominium: 'คอนโดสูง', low_rise_condominium: 'คอนโดเตี้ย', housing_estate: 'หมู่บ้านจัดสรร',
    mixed_use: 'โครงการผสม', commercial: 'อาคารพาณิชย์', other: 'อื่น ๆ', unknown: 'ยังไม่ทราบ',
    planned: 'วางแผน', under_construction: 'กำลังก่อสร้าง', completed: 'สร้างเสร็จ', on_hold: 'พักโครงการ', cancelled: 'ยกเลิก',
    studio: 'สตูดิโอ', one_bedroom: '1 ห้องนอน', one_bedroom_plus: '1 ห้องนอน พลัส', two_bedroom: '2 ห้องนอน',
    three_bedroom: '3 ห้องนอน', penthouse: 'เพนท์เฮาส์', lobby: 'ล็อบบี้', pool: 'สระว่ายน้ำ', gym: 'ฟิตเนส',
    fitness_studio: 'สตูดิโอออกกำลังกาย', spa: 'สปา', salon: 'ซาลอน', garden: 'สวน', lounge: 'เลานจ์',
    concierge: 'คอนเซียร์จ', security: 'รักษาความปลอดภัย', parking: 'ที่จอดรถ', jacuzzi: 'จากุซซี', sky_bar: 'สกายบาร์',
    private_pods: 'ห้องพักผ่อนส่วนตัว', coworking: 'พื้นที่ทำงานร่วม', playground: 'สนามเด็กเล่น', sauna: 'ซาวน่า', ev_charging: 'ที่ชาร์จรถไฟฟ้า',
};

type ProfileData = NonNullable<ServicePackage['profile_data']>;

export default function CatalogProfileFields({ schema, value, onChange, errors }: {
    schema: CatalogProfileSchema; value: ProfileData; onChange: (value: ProfileData) => void; errors: Record<string, string>;
}) {
    const update = (name: string, next: ProfileData[string]) => onChange({ ...value, [name]: next });
    return (
        <fieldset className="space-y-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-900/30">
            <legend className="px-2 font-semibold">{schema.label}</legend>
            <p className="text-sm text-slate-500">กรอกเฉพาะข้อมูลที่มีหลักฐาน ช่องว่างหมายถึงยังไม่ทราบ ไม่ใช่ศูนย์หรือไม่มีบริการ</p>
            <div className="grid gap-4 sm:grid-cols-2">
                {Object.entries(schema.fields).map(([name, field]) => {
                    const current = value[name];
                    const error = errors[`profile_data.${name}`];
                    const label = labels[name] ?? name;
                    if (field.type === 'enum_list') {
                        const selected = Array.isArray(current) ? current : [];
                        return <fieldset key={name} className="space-y-2 sm:col-span-2">
                            <legend className="text-sm font-medium">{label}</legend>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {field.values?.map(option => <label key={option} className="flex items-center gap-2 rounded-lg border border-slate-200 p-2 text-sm dark:border-slate-700">
                                    <input type="checkbox" checked={selected.includes(option)} onChange={e => update(name, e.target.checked ? [...selected, option] : selected.filter(v => v !== option))} />
                                    {labels[option] ?? option}
                                </label>)}
                            </div>
                            <button type="button" className="text-xs text-slate-500 underline" onClick={() => update(name, null)}>ไม่ทราบข้อมูลรายการนี้</button>
                            {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
                        </fieldset>;
                    }
                    if (field.type === 'numeric_range') {
                        const range = current && typeof current === 'object' && !Array.isArray(current) ? current : { min: null, max: null, unit: field.unit ?? 'sqm' };
                        return <Field key={name} label={label} error={error}>
                            <div className="flex items-center gap-2">
                                {(['min', 'max'] as const).map(bound => <TextInput key={bound} aria-label={`${label} ${bound === 'min' ? 'ต่ำสุด' : 'สูงสุด'}`} type="number" step="any" min={field.min} max={field.max} value={range[bound] ?? ''}
                                    onChange={e => update(name, { ...range, [bound]: e.target.value === '' ? null : Number(e.target.value) })} />)}
                            </div>
                            <button type="button" className="mt-1 text-xs text-slate-500 underline" onClick={() => update(name, null)}>ล้างช่วงพื้นที่</button>
                        </Field>;
                    }
                    return <Field key={name} label={label} error={error}>
                        {field.type === 'enum' ? <SelectInput aria-label={label} aria-invalid={Boolean(error)} value={typeof current === 'string' ? current : ''} onChange={e => update(name, e.target.value || null)}>
                            <option value="">— ไม่ระบุ —</option>
                            {field.values?.map(option => <option key={option} value={option}>{labels[option] ?? option}</option>)}
                        </SelectInput> : <TextInput
                            aria-label={label} aria-invalid={Boolean(error)}
                            type={['integer', 'number'].includes(field.type) ? 'number' : ['date', 'month'].includes(field.type) ? field.type : 'text'}
                            min={field.min} max={field.max} maxLength={field.max_length} step={field.type === 'integer' ? 1 : 'any'}
                            value={typeof current === 'string' || typeof current === 'number' ? current : ''}
                            onChange={e => update(name, e.target.value === '' ? null : ['integer', 'number'].includes(field.type) ? Number(e.target.value) : e.target.value)} />}
                    </Field>;
                })}
            </div>
        </fieldset>
    );
}
