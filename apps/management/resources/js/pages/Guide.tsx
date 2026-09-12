import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    BookOpen,
    BookOpenCheck,
    Bot,
    CheckCircle2,
    Database,
    FileSpreadsheet,
    HelpCircle,
    Info,
    Package,
    ToggleRight,
} from 'lucide-react';
import { useState } from 'react';
import AdminLayout from '@/components/AdminLayout';
import { routes } from '@/lib/routes';

type Language = 'th' | 'en';

const resourceDefinitions = [
    { key: 'packages', icon: Package, href: routes.packages.create },
    { key: 'faqs', icon: HelpCircle, href: routes.faqs.create },
    { key: 'knowledge', icon: BookOpen, href: routes.knowledge.create },
] as const;

const content = {
    th: {
        pageTitle: 'คู่มือการใช้งาน',
        languageLabel: 'เลือกภาษาคู่มือ',
        introTitle: 'จัดการข้อมูลทรัพย์ให้ถูกที่ แล้วควบคุมสิ่งที่ AI ใช้ตอบ',
        introBody: 'Aion3 Knowledge Management เก็บรายการทรัพย์ FAQ และความรู้ของธุรกิจไว้เป็นข้อมูลกลาง เมื่อผ่านสถานะพร้อมใช้งาน AI Assistant จะค้นผ่าน Management APIs เพื่อตอบลูกค้าใน Chatwoot ทั้ง LINE และ WhatsApp',
        important: 'การบันทึกข้อมูลไม่ได้ฝึกโมเดล AI แบบถาวร แต่เป็นการอัปเดตแหล่งข้อมูลที่ระบบเรียกใช้ขณะตอบแต่ละครั้ง',
        chooseTitle: 'ควรเพิ่มข้อมูลตรงไหน',
        chooseBody: 'เลือกประเภทตามลักษณะของคำตอบที่ต้องการให้ลูกค้าได้รับ',
        openForm: 'เปิดฟอร์มเพิ่มข้อมูล',
        resources: {
            packages: {
                title: 'รายการทรัพย์',
                description: 'ใช้เก็บประเภททรัพย์ ซื้อ/เช่า สถานะ ทำเล ราคา ห้อง พื้นที่ ชั้น รูปหลัก และคำค้น เพื่อให้ AI ค้นรายการที่ตรงเงื่อนไข',
                example: 'ตัวอย่าง: คอนโด 2 ห้องนอน ทองหล่อ สำหรับขาย ราคาไม่เกิน 8 ล้านบาท',
            },
            faqs: {
                title: 'คำถามพบบ่อย',
                description: 'ใช้กับคำถามที่มีคำตอบชัดเจนและถูกถามซ้ำ เช่น เวลาทำการ ขั้นตอนฝากขาย เอกสาร หรือเงื่อนไขบริการ',
                example: 'ตัวอย่าง: ฝากขายทรัพย์ต้องใช้เอกสารอะไรบ้าง → ระบุรายการเอกสารที่ตรวจสอบแล้ว',
            },
            knowledge: {
                title: 'คลังความรู้',
                description: 'ใช้กับข้อมูลทั่วไป นโยบาย ขั้นตอน ที่อยู่ แผนที่ เบอร์ติดต่อ เวลาทำการ และเรื่องที่ไม่ใช่รายการทรัพย์หรือ FAQ',
                example: 'ตัวอย่าง: ที่อยู่ ช่องทางติดต่อ วิธีเดินทาง และนโยบายของธุรกิจ',
            },
        },
        flowTitle: 'ข้อมูลเดินทางอย่างไร',
        flowBody: 'ลำดับตั้งแต่ผู้ดูแลเพิ่มข้อมูลจนถึงการตรวจคำตอบของ AI Assistant',
        steps: [
            { title: 'เพิ่มข้อมูล', body: 'รายการทรัพย์เพิ่มผ่านฟอร์มหรือนำเข้า Excel/CSV ได้ ส่วน FAQ และคลังความรู้ให้กรอกผ่านหน้าเว็บทีละรายการ' },
            { title: 'ตรวจและเปิดสถานะ', body: 'ตรวจข้อความ ราคา และเงื่อนไข จากนั้นเปิดใช้งาน รายการทรัพย์ต้องเปิด “เผยแพร่” เพิ่มอีกหนึ่งสถานะ' },
            { title: 'เปิดให้ AI ค้นหา', body: 'ทรัพย์ต้องใช้งาน เผยแพร่ อยู่ในช่วงวันที่มีผล และมีสถานะพร้อมเสนอ จึงจะถูกคืนผ่าน Catalog Search API' },
            { title: 'ตรวจคำตอบ', body: 'กลับมาที่แดชบอร์ดเพื่อดูว่าลูกค้าถามอะไร AI ตอบอะไร และตอบสำเร็จหรือไม่' },
        ],
        statusTitle: 'สถานะที่ต้องเข้าใจ',
        activeTitle: 'ใช้งาน (Active)',
        activeBody: 'ใช้กับรายการทรัพย์ FAQ และคลังความรู้ เมื่อปิดสถานะ รายการยังถูกเก็บไว้แต่ Management APIs จะไม่ส่งให้ AI ใช้ตอบ',
        publishedTitle: 'เผยแพร่ (Published)',
        publishedBody: 'ใช้กับรายการทรัพย์เท่านั้น ต้องเปิด “ใช้งาน” และ “เผยแพร่” อยู่ในช่วงวันที่มีผล และมีสถานะ “พร้อมเสนอ” จึงจะพร้อมให้ AI ใช้ตอบ',
        excelTitle: 'เมื่อมีข้อมูลจำนวนมาก',
        excelBody: 'นำเข้าได้เฉพาะรายการทรัพย์ ไปที่หน้า นำเข้ารายการทรัพย์ ดาวน์โหลดไฟล์ตัวอย่าง แล้วอัปโหลดเพื่อตรวจสอบก่อน ระบบจะข้าม code ซ้ำ ไม่เขียนทับข้อมูลเดิม และเพิ่มรายการใหม่เป็นฉบับร่างจนกว่าจะเปิดเผยแพร่',
        goImport: 'ไปหน้าการนำเข้ารายการทรัพย์',
        storageTitle: 'ข้อมูลถูกเก็บไว้ที่ไหนและไปต่ออย่างไร',
        storageBody: 'ทุกข้อมูลอยู่ในฐานข้อมูลของ Aion3 Knowledge Management ไม่ได้ถูกเก็บไว้ในไฟล์ Excel หลัง Import และไม่ถูกส่งไปฝึกโมเดลถาวร',
        storageHeaders: ['ข้อมูล', 'เก็บในระบบ', 'พร้อมให้ AI ใช้เมื่อ', 'นำไปใช้ตอบ'],
        storageRows: [
            ['รายการทรัพย์', 'Catalog Item', 'ใช้งาน + เผยแพร่ + อยู่ในช่วงวันที่มีผล + พร้อมเสนอ', 'ประเภท ซื้อ/เช่า ทำเล ราคา ห้อง พื้นที่ ชั้น และรูปหลัก'],
            ['FAQ', 'รายการคำถามพบบ่อย', 'เปิดใช้งาน', 'คำถามที่ถูกถามซ้ำและมีคำตอบแน่นอน'],
            ['คลังความรู้', 'รายการความรู้', 'เปิดใช้งาน', 'ที่อยู่ ติดต่อ ขั้นตอน นโยบาย และข้อมูลทั่วไป'],
            ['ประวัติการตอบ', 'Analytics', 'บันทึกอัตโนมัติหลัง AI ตอบ', 'ตรวจสอบคำถาม คำตอบ ประเภท และสถานะ'],
        ],
        checklistTitle: 'ตรวจให้ครบก่อนเปิดใช้งานจริง',
        checklist: [
            'ลบหรือปิดข้อมูลจำลองที่ไม่ต้องการให้ลูกค้าเห็น',
            'ตรวจราคา วันที่มีผล เงื่อนไข เบอร์ติดต่อ และเวลาทำการ',
            'เปิดใช้งานและเผยแพร่รายการทรัพย์ พร้อมตรวจว่าสถานะเป็น “พร้อมเสนอ”',
            'อัปโหลดรูปหลัก แล้วทดลองดู Flex Card บน LINE',
            'ถ้าค้นหาไม่พบ ตรวจว่า AI ขออนุญาตก่อนผ่อนทำเล ราคา หรือคุณสมบัติ',
            'ทดลองถามผ่าน LINE แล้วตรวจคำตอบที่หน้าแดชบอร์ด',
        ],
        viewDashboard: 'เปิดแดชบอร์ด',
    },
    en: {
        pageTitle: 'User Guide',
        languageLabel: 'Select guide language',
        introTitle: 'Manage property data and control what the AI can answer',
        introBody: 'Aion3 Knowledge Management stores property listings, FAQs, and business knowledge. Eligible data is retrieved through Management APIs by the Chatwoot AI Assistant for LINE and WhatsApp conversations.',
        important: 'Saving information does not permanently train the AI model. It updates the data source retrieved while each answer is being prepared.',
        chooseTitle: 'Where should I add information?',
        chooseBody: 'Choose the section based on the kind of answer customers should receive.',
        openForm: 'Open the add form',
        resources: {
            packages: {
                title: 'Property listings',
                description: 'Store property category, sale or rent intent, availability, location, price, rooms, area, floor, primary image, and search terms.',
                example: 'Example: Two-bedroom condo for sale in Thong Lo under THB 8 million',
            },
            faqs: {
                title: 'Frequently asked questions',
                description: 'Use for recurring questions with a verified answer, such as business hours, consignment steps, required documents, or service terms.',
                example: 'Example: Which documents are needed for a property consignment?',
            },
            knowledge: {
                title: 'Knowledge base',
                description: 'Use for general information, policies, procedures, address, map, contact details, opening hours, and anything that is not a property listing or FAQ.',
                example: 'Example: Address, contact channels, directions, and business policies',
            },
        },
        flowTitle: 'How information moves through the system',
        flowBody: 'The sequence from adding information to reviewing the AI Assistant’s answer.',
        steps: [
            { title: 'Add information', body: 'Property listings can use a form or the current Excel/CSV template. FAQs and knowledge entries are added through their web forms.' },
            { title: 'Review and enable', body: 'Check structured facts, price, terms, and image URL. Listings must be Active, Published, in their effective window, and Available.' },
            { title: 'Make it searchable', body: 'Eligible listings are returned through Catalog Search; FAQs and knowledge use their respective Management APIs.' },
            { title: 'Review answers', body: 'Return to the dashboard to see what customers asked, what the AI answered, and whether delivery succeeded.' },
        ],
        statusTitle: 'Statuses you should understand',
        activeTitle: 'Active',
        activeBody: 'Used by property listings, FAQs, and knowledge entries. A disabled record remains stored but is not returned by Management APIs.',
        publishedTitle: 'Published',
        publishedBody: 'Used only by property listings. A listing must be Active, Published, in its effective date range, and Available before the AI can present it.',
        excelTitle: 'When you have many records',
        excelBody: 'Property import supports the current 23-column template and the legacy 9-column format. Files are previewed first, duplicate codes are skipped, and new rows remain unpublished drafts until reviewed.',
        goImport: 'Open property import',
        storageTitle: 'Where information is stored and what happens next',
        storageBody: 'All information is stored in the Aion3 Knowledge Management database. It does not remain only in the Excel file after import and is not used to permanently train the model.',
        storageHeaders: ['Information', 'Stored as', 'Available to the AI when', 'Used to answer'],
        storageRows: [
            ['Property listings', 'Catalog Items', 'Active + Published + in effective dates + Available', 'Category, sale/rent, location, price, rooms, area, floor, and primary image'],
            ['FAQs', 'FAQ records', 'Active', 'Recurring questions with a defined answer'],
            ['Knowledge', 'Knowledge entries', 'Active', 'Address, contact, procedures, policies, and general facts'],
            ['Answer history', 'Analytics', 'Recorded automatically after an AI reply', 'Review questions, answers, response types, and status'],
        ],
        checklistTitle: 'Checklist before going live',
        checklist: [
            'Remove or disable mock data that customers should not see.',
            'Verify prices, effective dates, terms, contact details, and opening hours.',
            'Enable and publish listings, and confirm that availability is set to Available.',
            'Upload a primary image, then test the resulting LINE Flex Card.',
            'Confirm that a zero-result search asks for consent before relaxing detail filters.',
            'Send test questions through LINE and review the answers on the dashboard.',
        ],
        viewDashboard: 'Open dashboard',
    },
} as const;

export default function Guide() {
    const [language, setLanguage] = useState<Language>('th');
    const t = content[language];

    const languageSwitcher = (
        <div className="inline-flex rounded-lg border border-slate-300 bg-white p-1" role="group" aria-label={t.languageLabel}>
            {(['th', 'en'] as const).map((value) => (
                <button
                    key={value}
                    type="button"
                    aria-pressed={language === value}
                    onClick={() => setLanguage(value)}
                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-600 ${
                        language === value ? 'bg-zinc-700 text-white' : 'text-slate-600 hover:bg-slate-100'
                    }`}
                >
                    {value === 'th' ? 'ไทย' : 'English'}
                </button>
            ))}
        </div>
    );

    return (
        <AdminLayout title={t.pageTitle} actions={languageSwitcher}>
            <Head title={t.pageTitle} />

            <section className="rounded-xl bg-slate-900 px-5 py-6 text-white sm:px-7">
                <div className="flex max-w-3xl items-start gap-4">
                    <BookOpenCheck className="mt-1 h-7 w-7 shrink-0 text-zinc-400" />
                    <div>
                        <h2 className="text-xl font-semibold text-white">{t.introTitle}</h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-300">{t.introBody}</p>
                        <div className="mt-4 flex items-start gap-2 rounded-lg bg-white/10 px-3.5 py-3 text-sm leading-6 text-slate-200">
                            <Info className="mt-0.5 h-4 w-4 shrink-0 text-zinc-300" />
                            <p>{t.important}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section className="mt-8">
                <h2 className="text-lg font-semibold text-slate-900">{t.chooseTitle}</h2>
                <p className="mt-1 text-sm text-slate-600">{t.chooseBody}</p>
                <div className="mt-4 overflow-hidden rounded-xl border border-slate-200 bg-white">
                    {resourceDefinitions.map((resource, index) => {
                        const Icon = resource.icon;
                        const item = t.resources[resource.key];
                        return (
                            <div
                                key={resource.key}
                                className={`grid gap-4 px-5 py-5 lg:grid-cols-[220px_minmax(0,1fr)_auto] lg:items-center ${index > 0 ? 'border-t border-slate-200' : ''}`}
                            >
                                <div className="flex items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-zinc-50 text-zinc-700">
                                        <Icon className="h-5 w-5" />
                                    </span>
                                    <h3 className="font-semibold text-slate-900">{item.title}</h3>
                                </div>
                                <div>
                                    <p className="text-sm leading-6 text-slate-700">{item.description}</p>
                                    <p className="mt-1 text-xs leading-5 text-slate-500">{item.example}</p>
                                </div>
                                <Link
                                    href={resource.href}
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-700 hover:text-zinc-800 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-600"
                                >
                                    {t.openForm}
                                    <ArrowRight className="h-4 w-4" />
                                </Link>
                            </div>
                        );
                    })}
                </div>
            </section>

            <section className="mt-8 rounded-xl border border-slate-200 bg-white px-5 py-6 sm:px-6">
                <h2 className="text-lg font-semibold text-slate-900">{t.flowTitle}</h2>
                <p className="mt-1 text-sm text-slate-600">{t.flowBody}</p>
                <ol className="mt-5 grid gap-5 md:grid-cols-4">
                    {t.steps.map((step, index) => (
                        <li key={step.title} className="relative">
                            <div className="flex items-center gap-3">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-700 text-sm font-semibold text-white">
                                    {index + 1}
                                </span>
                                <h3 className="font-semibold text-slate-900">{step.title}</h3>
                            </div>
                            <p className="mt-2 text-sm leading-6 text-slate-600">{step.body}</p>
                        </li>
                    ))}
                </ol>
            </section>

            <section className="mt-8">
                <h2 className="text-lg font-semibold text-slate-900">{t.statusTitle}</h2>
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <div className="rounded-xl border border-slate-200 bg-white p-5">
                        <div className="flex items-center gap-2 text-zinc-700">
                            <ToggleRight className="h-5 w-5" />
                            <h3 className="font-semibold text-slate-900">{t.activeTitle}</h3>
                        </div>
                        <p className="mt-2 text-sm leading-6 text-slate-600">{t.activeBody}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-5">
                        <div className="flex items-center gap-2 text-zinc-700">
                            <CheckCircle2 className="h-5 w-5" />
                            <h3 className="font-semibold text-slate-900">{t.publishedTitle}</h3>
                        </div>
                        <p className="mt-2 text-sm leading-6 text-slate-600">{t.publishedBody}</p>
                    </div>
                </div>
            </section>

            <section className="mt-8 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div className="flex max-w-3xl items-start gap-3">
                    <FileSpreadsheet className="mt-0.5 h-5 w-5 shrink-0 text-zinc-700" />
                    <div>
                        <h2 className="font-semibold text-slate-900">{t.excelTitle}</h2>
                        <p className="mt-1 text-sm leading-6 text-slate-600">{t.excelBody}</p>
                    </div>
                </div>
                <Link
                    href={routes.imports.index}
                    className="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-zinc-700 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-zinc-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-600"
                >
                    {t.goImport}
                    <ArrowRight className="h-4 w-4" />
                </Link>
            </section>

            <section className="mt-8">
                <div className="flex items-start gap-3">
                    <Database className="mt-0.5 h-5 w-5 shrink-0 text-zinc-700" />
                    <div>
                        <h2 className="text-lg font-semibold text-slate-900">{t.storageTitle}</h2>
                        <p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">{t.storageBody}</p>
                    </div>
                </div>
                <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <table className="min-w-full text-left text-sm">
                        <thead className="border-b border-slate-200 bg-slate-50">
                            <tr>
                                {t.storageHeaders.map((header) => (
                                    <th key={header} className="whitespace-nowrap px-4 py-3 text-xs font-semibold text-slate-600">{header}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {t.storageRows.map((row) => (
                                <tr key={row[0]} className="align-top hover:bg-slate-50">
                                    {row.map((cell, index) => (
                                        <td key={cell} className={`px-4 py-3 leading-6 ${index === 0 ? 'font-medium text-slate-900' : 'text-slate-600'}`}>{cell}</td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="mt-8 rounded-xl bg-zinc-50 px-5 py-6 sm:px-6">
                <div className="flex items-center gap-2">
                    <Bot className="h-5 w-5 text-zinc-800" />
                    <h2 className="font-semibold text-zinc-950">{t.checklistTitle}</h2>
                </div>
                <ul className="mt-4 grid gap-3 md:grid-cols-2">
                    {t.checklist.map((item) => (
                        <li key={item} className="flex items-start gap-2 text-sm leading-6 text-zinc-950">
                            <CheckCircle2 className="mt-1 h-4 w-4 shrink-0 text-zinc-700" />
                            {item}
                        </li>
                    ))}
                </ul>
                <Link
                    href={routes.dashboard}
                    className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-zinc-800 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-700"
                >
                    <BarChart3 className="h-4 w-4" />
                    {t.viewDashboard}
                </Link>
            </section>
        </AdminLayout>
    );
}
