<?php

namespace Database\Seeders;

use App\Models\Faq;
use App\Models\KnowledgeEntry;
use App\Models\PackageCategory;
use App\Models\ServicePackage;
use Illuminate\Database\Seeder;

class RealEstateDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('Demo seeding is disabled in production.');
        }
        $definitions = [
            ['key' => 'bedrooms', 'label_th' => 'ห้องนอน', 'type' => 'number', 'operators' => ['eq', 'gte', 'lte'], 'searchable' => true],
            ['key' => 'bathrooms', 'label_th' => 'ห้องน้ำ', 'type' => 'number', 'operators' => ['eq', 'gte', 'lte'], 'searchable' => true],
            ['key' => 'usable_area_sqm', 'label_th' => 'พื้นที่ใช้สอย ตร.ม.', 'type' => 'number', 'unit' => 'sqm', 'operators' => ['gte', 'lte'], 'searchable' => true],
            ['key' => 'land_area_sqw', 'label_th' => 'พื้นที่ดิน ตร.ว.', 'type' => 'number', 'unit' => 'sqw', 'operators' => ['gte', 'lte'], 'searchable' => true],
            ['key' => 'floor', 'label_th' => 'ชั้น', 'type' => 'number', 'operators' => ['eq', 'gte', 'lte'], 'searchable' => true],
        ];
        $categories = collect([
            ['slug' => 'condo', 'name_th' => 'คอนโดมิเนียม', 'name_en' => 'Condominium'],
            ['slug' => 'house', 'name_th' => 'บ้าน', 'name_en' => 'House'],
            ['slug' => 'land', 'name_th' => 'ที่ดิน', 'name_en' => 'Land'],
            ['slug' => 'commercial', 'name_th' => 'อสังหาริมทรัพย์เพื่อพาณิชย์', 'name_en' => 'Commercial property'],
        ])->mapWithKeys(fn (array $data) => [$data['slug'] => PackageCategory::firstOrCreate(
            ['slug' => $data['slug']], $data + ['attribute_definitions' => $definitions, 'sort_order' => 10, 'is_active' => true],
        )]);

        $this->seedCatalog($categories);
        $this->seedFaqs();
        $this->seedKnowledge();
        $this->seedBusinessProfile();
    }

    private function seedBusinessProfile(): void
    {
        if (\App\Models\BusinessProfile::query()->exists()) {
            return;
        }
        \App\Models\BusinessProfile::create([
            'business_name' => 'บิว Property (Bill Property)',
            'business_description' => 'ตัวแทนและที่ปรึกษาด้านอสังหาริมทรัพย์ครบวงจร บริการรับฝากขาย ฝากเช่า จัดหาบ้าน คอนโด ที่ดิน และอาคารพาณิชย์ในกรุงเทพฯ และปริมณฑล พร้อมดูแลสินเชื่อและนิติกรรมสัญญาครบวงจร',
            'services_offered' => 'รับฝากขาย-ฝากเช่า คอนโด/บ้าน/ที่ดิน, จัดหาทรัพย์ตามงบประมาณ, ให้คำปรึกษาสินเชื่อและยื่นกู้ธนาคารฟรี, บริการพาโอนกรรมสิทธิ์ ณ สำนักงานที่ดิน',
            'service_areas' => 'กรุงเทพฯ และปริมณฑล (เน้นโซนสุขุมวิท, บางนา, ศรีนครินทร์, อ่อนนุช, ลาดกระบัง, สมุทรปราการ)',
            'business_hours' => 'เปิดทำการทุกวัน จันทร์ - อาทิตย์ เวลา 09:00 - 19:00 น.',
            'contact_channels' => 'LINE: @billproperty, โทร: 081-234-5678, สำนักงาน: บางนา-ตราด กม.4 กรุงเทพฯ',
            'conversation_tone' => 'สุภาพ เป็นกันเอง มีความเชี่ยวชาญ ให้ข้อมูลตรงไปตรงมา ชัดเจน น่าเชื่อถือ ลงท้ายด้วยครับ',
            'always_escalate_topics' => 'เรื่องการโอนเงินจอง/มัดจำ, การร้องเรียน, สัญญาจะซื้อจะขายที่ต้องลงนามเฉพาะบุคคล',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<string, PackageCategory>  $categories
     */
    private function seedCatalog($categories): void
    {
        $condos = [
            ['code' => 'DEMO-CONDO-001', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง บางนา 2 ห้องนอน', 'price' => 3850000, 'district' => 'บางนา', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'บางนา เรสซิเดนซ์ (ตัวอย่าง)', 'bedrooms' => 2, 'bathrooms' => 2, 'usable_area_sqm' => 58, 'floor' => 12],
            ['code' => 'DEMO-CONDO-002', 'transaction_type' => 'rent', 'name_th' => 'คอนโดตัวอย่าง อ่อนนุช 1 ห้องนอน', 'price' => 22000, 'district' => 'สวนหลวง', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'อ่อนนุช เพลส (ตัวอย่าง)', 'bedrooms' => 1, 'bathrooms' => 1, 'usable_area_sqm' => 36, 'floor' => 8],
            ['code' => 'DEMO-CONDO-003', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง ลาดพร้าว สตูดิโอ', 'price' => 2150000, 'district' => 'ลาดพร้าว', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'ลาดพร้าว สแควร์ (ตัวอย่าง)', 'bedrooms' => 1, 'bathrooms' => 1, 'usable_area_sqm' => 26, 'floor' => 5],
            ['code' => 'DEMO-CONDO-004', 'transaction_type' => 'rent', 'name_th' => 'คอนโดตัวอย่าง สุขุมวิท 3 ห้องนอน', 'price' => 55000, 'district' => 'วัฒนา', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'สุขุมวิท การ์เดน (ตัวอย่าง)', 'bedrooms' => 3, 'bathrooms' => 2, 'usable_area_sqm' => 95, 'floor' => 22],
            ['code' => 'DEMO-CONDO-005', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง บางนา 1 ห้องนอน', 'price' => 2600000, 'district' => 'บางนา', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'บางนา เรสซิเดนซ์ (ตัวอย่าง)', 'bedrooms' => 1, 'bathrooms' => 1, 'usable_area_sqm' => 32, 'floor' => 6],
            ['code' => 'DEMO-CONDO-006', 'transaction_type' => 'rent', 'name_th' => 'คอนโดตัวอย่าง รามคำแหง 2 ห้องนอน', 'price' => 18000, 'district' => 'บางกะปิ', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'รามคำแหง วิว (ตัวอย่าง)', 'bedrooms' => 2, 'bathrooms' => 1, 'usable_area_sqm' => 48, 'floor' => 9],
            ['code' => 'DEMO-CONDO-007', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง แจ้งวัฒนะ 2 ห้องนอน', 'price' => 3200000, 'district' => 'ปากเกร็ด', 'province' => 'นนทบุรี', 'project_name' => 'แจ้งวัฒนะ เพลส (ตัวอย่าง)', 'bedrooms' => 2, 'bathrooms' => 2, 'usable_area_sqm' => 54, 'floor' => 14],
            ['code' => 'DEMO-CONDO-008', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง อ่อนนุช 2 ห้องนอน', 'price' => 4100000, 'district' => 'สวนหลวง', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'อ่อนนุช เพลส (ตัวอย่าง)', 'bedrooms' => 2, 'bathrooms' => 2, 'usable_area_sqm' => 60, 'floor' => 15],
            ['code' => 'DEMO-CONDO-009', 'transaction_type' => 'rent', 'name_th' => 'คอนโดตัวอย่าง ลาดพร้าว 1 ห้องนอน', 'price' => 13500, 'district' => 'ลาดพร้าว', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'ลาดพร้าว สแควร์ (ตัวอย่าง)', 'bedrooms' => 1, 'bathrooms' => 1, 'usable_area_sqm' => 28, 'floor' => 3],
            ['code' => 'DEMO-CONDO-010', 'transaction_type' => 'sale', 'name_th' => 'คอนโดตัวอย่าง สุขุมวิท 1 ห้องนอน', 'price' => 4650000, 'district' => 'วัฒนา', 'province' => 'กรุงเทพมหานคร', 'project_name' => 'สุขุมวิท การ์เดน (ตัวอย่าง)', 'bedrooms' => 1, 'bathrooms' => 1, 'usable_area_sqm' => 34, 'floor' => 18],
        ];

        $houses = [
            ['code' => 'DEMO-HOUSE-001', 'transaction_type' => 'sale', 'name_th' => 'บ้านเดี่ยวตัวอย่าง ศรีนครินทร์', 'price' => 7900000, 'district' => 'เมืองสมุทรปราการ', 'province' => 'สมุทรปราการ', 'bedrooms' => 3, 'bathrooms' => 3, 'usable_area_sqm' => 180, 'land_area_sqw' => 56],
            ['code' => 'DEMO-HOUSE-002', 'transaction_type' => 'rent', 'name_th' => 'บ้านเดี่ยวตัวอย่าง บางนา', 'price' => 35000, 'district' => 'บางนา', 'province' => 'กรุงเทพมหานคร', 'bedrooms' => 3, 'bathrooms' => 2, 'usable_area_sqm' => 150, 'land_area_sqw' => 50],
            ['code' => 'DEMO-HOUSE-003', 'transaction_type' => 'sale', 'name_th' => 'ทาวน์โฮมตัวอย่าง ลำลูกกา', 'price' => 3200000, 'district' => 'ลำลูกกา', 'province' => 'ปทุมธานี', 'bedrooms' => 3, 'bathrooms' => 2, 'usable_area_sqm' => 120, 'land_area_sqw' => 18],
            ['code' => 'DEMO-HOUSE-004', 'transaction_type' => 'sale', 'name_th' => 'บ้านเดี่ยวตัวอย่าง วงแหวนตะวันตก', 'price' => 6500000, 'district' => 'บางใหญ่', 'province' => 'นนทบุรี', 'bedrooms' => 4, 'bathrooms' => 3, 'usable_area_sqm' => 210, 'land_area_sqw' => 62],
            ['code' => 'DEMO-HOUSE-005', 'transaction_type' => 'rent', 'name_th' => 'ทาวน์โฮมตัวอย่าง รังสิต', 'price' => 15000, 'district' => 'ธัญบุรี', 'province' => 'ปทุมธานี', 'bedrooms' => 2, 'bathrooms' => 2, 'usable_area_sqm' => 90, 'land_area_sqw' => 16],
            ['code' => 'DEMO-HOUSE-006', 'transaction_type' => 'sale', 'name_th' => 'บ้านเดี่ยวตัวอย่าง ศรีนครินทร์ 2', 'price' => 9200000, 'district' => 'เมืองสมุทรปราการ', 'province' => 'สมุทรปราการ', 'bedrooms' => 4, 'bathrooms' => 4, 'usable_area_sqm' => 240, 'land_area_sqw' => 70],
            ['code' => 'DEMO-HOUSE-007', 'transaction_type' => 'sale', 'name_th' => 'ทาวน์โฮมตัวอย่าง บางบัวทอง', 'price' => 2650000, 'district' => 'บางบัวทอง', 'province' => 'นนทบุรี', 'bedrooms' => 2, 'bathrooms' => 2, 'usable_area_sqm' => 100, 'land_area_sqw' => 17],
            ['code' => 'DEMO-HOUSE-008', 'transaction_type' => 'rent', 'name_th' => 'บ้านเดี่ยวตัวอย่าง วงแหวนตะวันตก', 'price' => 42000, 'district' => 'บางใหญ่', 'province' => 'นนทบุรี', 'bedrooms' => 4, 'bathrooms' => 3, 'usable_area_sqm' => 210, 'land_area_sqw' => 62],
        ];

        $lands = [
            ['code' => 'DEMO-LAND-001', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง ใกล้บางนา-ตราด', 'price' => 12500000, 'district' => 'บางพลี', 'province' => 'สมุทรปราการ', 'land_area_sqw' => 100],
            ['code' => 'DEMO-LAND-002', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง ลำลูกกา คลอง 7', 'price' => 4800000, 'district' => 'ลำลูกกา', 'province' => 'ปทุมธานี', 'land_area_sqw' => 120],
            ['code' => 'DEMO-LAND-003', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง วงแหวนตะวันออก', 'price' => 18900000, 'district' => 'ลำลูกกา', 'province' => 'ปทุมธานี', 'land_area_sqw' => 210],
            ['code' => 'DEMO-LAND-004', 'transaction_type' => 'rent', 'name_th' => 'ที่ดินให้เช่าตัวอย่าง บางบัวทอง', 'price' => 25000, 'district' => 'บางบัวทอง', 'province' => 'นนทบุรี', 'land_area_sqw' => 400],
            ['code' => 'DEMO-LAND-005', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง ศรีนครินทร์', 'price' => 22000000, 'district' => 'เมืองสมุทรปราการ', 'province' => 'สมุทรปราการ', 'land_area_sqw' => 150],
            ['code' => 'DEMO-LAND-006', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินเปล่าตัวอย่าง แจ้งวัฒนะ', 'price' => 9500000, 'district' => 'ปากเกร็ด', 'province' => 'นนทบุรี', 'land_area_sqw' => 80],
            ['code' => 'DEMO-LAND-007', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง รังสิต คลอง 4', 'price' => 6300000, 'district' => 'ธัญบุรี', 'province' => 'ปทุมธานี', 'land_area_sqw' => 130],
            ['code' => 'DEMO-LAND-008', 'transaction_type' => 'sale', 'name_th' => 'ที่ดินตัวอย่าง บางนา กม.15', 'price' => 15200000, 'district' => 'บางพลี', 'province' => 'สมุทรปราการ', 'land_area_sqw' => 160],
        ];

        $commercial = [
            ['code' => 'DEMO-COMM-001', 'transaction_type' => 'rent', 'name_th' => 'อาคารพาณิชย์ตัวอย่าง บางนา 3 ชั้น', 'price' => 45000, 'district' => 'บางนา', 'province' => 'กรุงเทพมหานคร', 'usable_area_sqm' => 240, 'land_area_sqw' => 20],
            ['code' => 'DEMO-COMM-002', 'transaction_type' => 'sale', 'name_th' => 'อาคารพาณิชย์ตัวอย่าง ลาดพร้าว 4 ชั้น', 'price' => 13500000, 'district' => 'ลาดพร้าว', 'province' => 'กรุงเทพมหานคร', 'usable_area_sqm' => 320, 'land_area_sqw' => 22],
            ['code' => 'DEMO-COMM-003', 'transaction_type' => 'rent', 'name_th' => 'พื้นที่ค้าปลีกตัวอย่าง สุขุมวิท', 'price' => 80000, 'district' => 'วัฒนา', 'province' => 'กรุงเทพมหานคร', 'usable_area_sqm' => 150],
            ['code' => 'DEMO-COMM-004', 'transaction_type' => 'sale', 'name_th' => 'โกดังตัวอย่าง บางพลี', 'price' => 18500000, 'district' => 'บางพลี', 'province' => 'สมุทรปราการ', 'usable_area_sqm' => 800, 'land_area_sqw' => 250],
            ['code' => 'DEMO-COMM-005', 'transaction_type' => 'rent', 'name_th' => 'สำนักงานให้เช่าตัวอย่าง แจ้งวัฒนะ', 'price' => 60000, 'district' => 'ปากเกร็ด', 'province' => 'นนทบุรี', 'usable_area_sqm' => 200],
            ['code' => 'DEMO-COMM-006', 'transaction_type' => 'sale', 'name_th' => 'อาคารพาณิชย์ตัวอย่าง รังสิต 3 ชั้น', 'price' => 8900000, 'district' => 'ธัญบุรี', 'province' => 'ปทุมธานี', 'usable_area_sqm' => 210, 'land_area_sqw' => 18],
        ];

        $services = [
            ['code' => 'DEMO-SVC-001', 'name_th' => 'บริการฝากขายอสังหาริมทรัพย์', 'description_th' => 'ฝากขายคอนโด บ้าน หรือที่ดินกับทีมงาน พร้อมทำการตลาดและจัดการเอกสารให้ครบวงจร (ข้อมูลตัวอย่าง)', 'keywords' => 'ฝากขาย,นายหน้า,ขายฝาก'],
            ['code' => 'DEMO-SVC-002', 'name_th' => 'บริการประเมินราคาทรัพย์สินฟรี', 'description_th' => 'ประเมินราคาตลาดปัจจุบันของคอนโด บ้าน หรือที่ดิน โดยไม่มีค่าใช้จ่าย (ข้อมูลตัวอย่าง)', 'keywords' => 'ประเมินราคา,ฟรี'],
            ['code' => 'DEMO-SVC-003', 'name_th' => 'บริการฝากเช่าอสังหาริมทรัพย์', 'description_th' => 'ฝากปล่อยเช่าคอนโดหรือบ้าน พร้อมหาผู้เช่าและตรวจสอบเอกสารให้ (ข้อมูลตัวอย่าง)', 'keywords' => 'ฝากเช่า,ปล่อยเช่า'],
            ['code' => 'DEMO-SVC-004', 'name_th' => 'บริการที่ปรึกษาสินเชื่อบ้าน', 'description_th' => 'ให้คำปรึกษาเรื่องสินเชื่อกับธนาคารพันธมิตร ช่วยเตรียมเอกสารยื่นกู้ (ข้อมูลตัวอย่าง)', 'keywords' => 'สินเชื่อ,กู้บ้าน,ที่ปรึกษา'],
        ];

        foreach ($condos as $item) {
            $this->upsertPackage($item, $categories['condo']->id, 'property', 'คอนโด,'.($item['district'] ?? ''));
        }
        foreach ($houses as $item) {
            $this->upsertPackage($item, $categories['house']->id, 'property', 'บ้าน,'.($item['district'] ?? ''));
        }
        foreach ($lands as $item) {
            $this->upsertPackage($item, $categories['land']->id, 'property', 'ที่ดิน,'.($item['district'] ?? ''));
        }
        foreach ($commercial as $item) {
            $this->upsertPackage($item, $categories['commercial']->id, 'property', 'พาณิชย์,'.($item['district'] ?? ''));
        }
        foreach ($services as $item) {
            ServicePackage::firstOrCreate(['code' => $item['code']], [
                'category_id' => null,
                'item_type' => 'service',
                'name_th' => $item['name_th'],
                'description_th' => $item['description_th'],
                'transaction_type' => 'service',
                'price' => 0,
                'currency' => 'THB',
                'availability' => 'available',
                'keywords' => $item['keywords'],
                'is_active' => true,
                'is_published' => true,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertPackage(array $item, int $categoryId, string $itemType, string $keywords): void
    {
        $defaults = array_merge($item, [
            'category_id' => $categoryId,
            'item_type' => $itemType,
            'description_th' => 'ข้อมูลตัวอย่างสำหรับทดสอบระบบ ไม่ใช่ประกาศจริง',
            'currency' => 'THB',
            'availability' => 'available',
            'location_text' => trim(($item['district'] ?? '').' '.($item['province'] ?? '')),
            'keywords' => $keywords,
            'is_active' => true,
            'is_published' => true,
        ]);
        ServicePackage::firstOrCreate(['code' => $item['code']], $defaults);
    }

    private function seedFaqs(): void
    {
        $faqs = [
            ['q' => 'ข้อมูลอสังหาริมทรัพย์ในระบบเป็นข้อมูลจริงหรือไม่', 'a' => 'ข้อมูลตัวอย่างเริ่มต้นใช้สำหรับทดสอบระบบเท่านั้น กรุณาตรวจสอบรายการจริงกับเจ้าหน้าที่ก่อนตัดสินใจ', 'category' => 'policy', 'tags' => 'ตัวอย่าง,อสังหาริมทรัพย์'],
            ['q' => 'ค่าธรรมเนียมการโอนกรรมสิทธิ์ใครเป็นคนจ่าย', 'a' => 'ค่าธรรมเนียมการโอนกรรมสิทธิ์ปกติแบ่งจ่ายฝ่ายละครึ่งระหว่างผู้ซื้อและผู้ขาย เว้นแต่ตกลงกันเป็นอย่างอื่นในสัญญา', 'category' => 'transaction', 'tags' => 'ค่าโอน,ค่าธรรมเนียม'],
            ['q' => 'ต้องเตรียมเอกสารอะไรบ้างสำหรับการซื้อบ้าน', 'a' => 'บัตรประชาชน ทะเบียนบ้าน และเอกสารแสดงรายได้ (สลิปเงินเดือนหรือ statement ธนาคาร) หากขอสินเชื่อ ต้องเตรียมเอกสารเพิ่มตามที่ธนาคารกำหนด', 'category' => 'documents', 'tags' => 'เอกสาร,ซื้อบ้าน'],
            ['q' => 'ขั้นตอนการฝากขายทรัพย์มีอะไรบ้าง', 'a' => 'ติดต่อทีมงานเพื่อนัดสำรวจทรัพย์ ประเมินราคา เซ็นสัญญาฝากขาย จากนั้นทีมงานจะเริ่มทำการตลาดให้', 'category' => 'service', 'tags' => 'ฝากขาย,ขั้นตอน'],
            ['q' => 'ค่าคอมมิชชั่นการฝากขายคิดอย่างไร', 'a' => 'ค่าคอมมิชชั่นมาตรฐานอยู่ที่ 3% ของราคาขาย ชำระเมื่อการซื้อขายเสร็จสมบูรณ์เท่านั้น', 'category' => 'service', 'tags' => 'ค่าคอม,ฝากขาย'],
            ['q' => 'เช่าคอนโดต้องวางมัดจำเท่าไหร่', 'a' => 'โดยทั่วไปวางมัดจำ 2 เดือน บวกค่าเช่าล่วงหน้า 1 เดือน แต่ละรายการอาจมีเงื่อนไขต่างกัน กรุณาสอบถามรายละเอียดของแต่ละยูนิต', 'category' => 'rent', 'tags' => 'มัดจำ,เช่า'],
            ['q' => 'สัญญาเช่าขั้นต่ำกี่เดือน', 'a' => 'สัญญาเช่าขั้นต่ำปกติคือ 1 ปี บางรายการรับสัญญาระยะสั้น 6 เดือนได้ ขึ้นกับเจ้าของทรัพย์', 'category' => 'rent', 'tags' => 'สัญญาเช่า,ระยะเวลา'],
            ['q' => 'นัดชมทรัพย์ได้อย่างไร', 'a' => 'แจ้งรหัสรายการที่สนใจกับเจ้าหน้าที่ ทีมงานจะนัดวันเวลาที่สะดวกและพาชมทรัพย์จริง', 'category' => 'service', 'tags' => 'นัดชม,ทรัพย์'],
            ['q' => 'ขอสินเชื่อบ้านต้องทำอย่างไร', 'a' => 'สามารถยื่นเรื่องผ่านธนาคารที่เราเป็นพันธมิตรได้โดยตรง ทีมงานช่วยเตรียมเอกสารและติดตามผลอนุมัติให้', 'category' => 'finance', 'tags' => 'สินเชื่อ,กู้บ้าน'],
            ['q' => 'ให้บริการพื้นที่ไหนบ้าง', 'a' => 'ปัจจุบันให้บริการหลักในกรุงเทพฯ ฝั่งตะวันออก (บางนา ลาดพร้าว สุขุมวิท) นนทบุรี และปทุมธานี', 'category' => 'general', 'tags' => 'พื้นที่บริการ,ทำเล'],
            ['q' => 'เวลาทำการของทีมงานคือช่วงไหน', 'a' => 'ทีมงานตอบแชทและออกให้บริการทุกวัน 09:00-19:00 น. นอกเวลานี้ AI ยังตอบคำถามเบื้องต้นให้ได้', 'category' => 'general', 'tags' => 'เวลาทำการ'],
            ['q' => 'ติดต่อทีมงานได้ช่องทางไหนบ้าง', 'a' => 'ติดต่อผ่านแชท LINE นี้ได้โดยตรง หรือฝากเบอร์โทรไว้ให้เจ้าหน้าที่ติดต่อกลับ', 'category' => 'general', 'tags' => 'ติดต่อ,ช่องทาง'],
            ['q' => 'ต่อรองราคาได้ไหม', 'a' => 'สามารถแจ้งราคาที่สนใจมาได้ ทีมงานจะเสนอให้เจ้าของทรัพย์พิจารณาอีกครั้ง', 'category' => 'transaction', 'tags' => 'ต่อรอง,ราคา'],
            ['q' => 'ชาวต่างชาติซื้อคอนโดได้ไหม เอกสารต่างกันหรือไม่', 'a' => 'ชาวต่างชาติซื้อคอนโดได้ตามสัดส่วนที่กฎหมายกำหนด ต้องมีหลักฐานการโอนเงินจากต่างประเทศเพิ่มเติมจากเอกสารทั่วไป', 'category' => 'documents', 'tags' => 'ต่างชาติ,เอกสาร'],
            ['q' => 'มีค่าใช้จ่ายแฝงอื่นนอกจากราคาขายไหม', 'a' => 'อาจมีค่าธรรมเนียมโอน ค่าจดจำนอง (ถ้ากู้) และภาษีที่เกี่ยวข้อง ทีมงานจะสรุปค่าใช้จ่ายทั้งหมดให้ก่อนตัดสินใจ', 'category' => 'transaction', 'tags' => 'ค่าใช้จ่าย,แฝง'],
            ['q' => 'บริการประเมินราคามีค่าใช้จ่ายไหม', 'a' => 'บริการประเมินราคาเบื้องต้นไม่มีค่าใช้จ่าย', 'category' => 'service', 'tags' => 'ประเมินราคา,ฟรี'],
            ['q' => 'ใช้เวลานานแค่ไหนกว่าจะขายทรัพย์ได้', 'a' => 'ขึ้นกับทำเลและราคา โดยเฉลี่ยทรัพย์ที่ตั้งราคาเหมาะสมจะปิดการขายภายใน 1-3 เดือน', 'category' => 'service', 'tags' => 'ระยะเวลา,ขาย'],
            ['q' => 'เช่าคอนโดเลี้ยงสัตว์ได้ไหม', 'a' => 'ขึ้นกับกฎของแต่ละนิติบุคคลและเจ้าของห้อง กรุณาแจ้งความต้องการเลี้ยงสัตว์ก่อน ทีมงานจะช่วยกรองรายการที่รับได้ให้', 'category' => 'rent', 'tags' => 'สัตว์เลี้ยง,เช่า'],
            ['q' => 'มีที่จอดรถให้ไหม', 'a' => 'คอนโดส่วนใหญ่มีที่จอดรถส่วนกลาง บ้านเดี่ยวและทาวน์โฮมมีที่จอดในตัว รายละเอียดแตกต่างกันตามแต่ละรายการ', 'category' => 'general', 'tags' => 'ที่จอดรถ'],
            ['q' => 'ค่าส่วนกลางเท่าไหร่', 'a' => 'ค่าส่วนกลางแตกต่างกันตามโครงการ โดยทั่วไปคอนโดอยู่ที่ 40-80 บาทต่อตารางเมตรต่อเดือน สอบถามยอดที่แน่นอนได้ตามรายการที่สนใจ', 'category' => 'general', 'tags' => 'ค่าส่วนกลาง'],
            ['q' => 'โอนเงินมัดจำอย่างไร', 'a' => 'โอนผ่านบัญชีธนาคารที่ระบุในสัญญาเท่านั้น และเก็บหลักฐานการโอนไว้ทุกครั้ง ทีมงานจะไม่ขอให้โอนเข้าบัญชีส่วนตัวนอกสัญญา', 'category' => 'transaction', 'tags' => 'โอนเงิน,มัดจำ'],
            ['q' => 'ยกเลิกการจองได้ไหม', 'a' => 'ขึ้นกับเงื่อนไขในสัญญาจอง โดยทั่วไปเงินมัดจำอาจไม่คืนหากยกเลิกฝ่ายเดียว กรุณาตรวจสอบเงื่อนไขก่อนวางมัดจำ', 'category' => 'transaction', 'tags' => 'ยกเลิก,จอง'],
            ['q' => 'มัดจำคืนได้ไหมถ้าไม่ผ่านการอนุมัติสินเชื่อ', 'a' => 'สัญญาส่วนใหญ่มีเงื่อนไขคืนมัดจำหากยื่นกู้ไม่ผ่านตามเงื่อนไขที่ระบุ กรุณาตรวจสอบเงื่อนไขนี้ในสัญญาก่อนเซ็น', 'category' => 'finance', 'tags' => 'มัดจำ,สินเชื่อ'],
            ['q' => 'เอกสารสำหรับการฝากขายมีอะไรบ้าง', 'a' => 'สำเนาโฉนดหรือหนังสือแสดงกรรมสิทธิ์ บัตรประชาชนเจ้าของ และหนังสือมอบอำนาจ (ถ้ามีผู้รับมอบอำนาจ)', 'category' => 'documents', 'tags' => 'เอกสาร,ฝากขาย'],
            ['q' => 'ตรวจสอบกรรมสิทธิ์ทรัพย์อย่างไรก่อนซื้อ', 'a' => 'ทีมงานช่วยตรวจสอบเบื้องต้นจากโฉนดและข้อมูลกรมที่ดินให้ แนะนำให้ตรวจสอบซ้ำที่สำนักงานที่ดินก่อนโอนกรรมสิทธิ์จริง', 'category' => 'transaction', 'tags' => 'กรรมสิทธิ์,ตรวจสอบ'],
            ['q' => 'มีประกันภัยทรัพย์สินให้ไหม', 'a' => 'เราไม่ได้จำหน่ายประกันภัยโดยตรง แต่แนะนำบริษัทประกันพันธมิตรให้ได้หากสนใจ', 'category' => 'general', 'tags' => 'ประกัน'],
            ['q' => 'ผ่อนดาวน์กับโครงการได้ไหม', 'a' => 'บางโครงการรับผ่อนดาวน์ตรงกับเจ้าของโครงการได้ ทีมงานจะแจ้งเงื่อนไขเฉพาะของแต่ละรายการให้', 'category' => 'finance', 'tags' => 'ผ่อนดาวน์'],
            ['q' => 'ซื้อในนามนิติบุคคลได้ไหม', 'a' => 'ซื้อในนามนิติบุคคลได้ ต้องเตรียมหนังสือรับรองบริษัทและมติที่ประชุมกรรมการเพิ่มเติมจากเอกสารทั่วไป', 'category' => 'documents', 'tags' => 'นิติบุคคล,ซื้อ'],
            ['q' => 'จำเป็นต้องใช้นายหน้าไหมถ้าซื้อโดยตรงกับเจ้าของ', 'a' => 'ไม่จำเป็น แต่ทีมงานช่วยตรวจสอบเอกสารและดำเนินการให้ราบรื่นขึ้น ค่าบริการจะแจ้งให้ทราบล่วงหน้าเสมอ', 'category' => 'service', 'tags' => 'นายหน้า'],
            ['q' => 'ทำสัญญาซื้อขายที่ไหน', 'a' => 'ทำสัญญาได้ที่สำนักงานของเรา หรือสำนักงานที่ดินในวันโอนกรรมสิทธิ์ ขึ้นกับประเภทสัญญา', 'category' => 'transaction', 'tags' => 'สัญญา,สถานที่'],
            ['q' => 'ภาษีที่เกี่ยวข้องกับการซื้อขายมีอะไรบ้าง', 'a' => 'หลักๆ คือภาษีธุรกิจเฉพาะหรืออากรแสตมป์ (แล้วแต่ระยะเวลาถือครอง) และภาษีเงินได้หัก ณ ที่จ่าย ทีมงานช่วยคำนวณเบื้องต้นให้ได้', 'category' => 'transaction', 'tags' => 'ภาษี'],
            ['q' => 'รอผลอนุมัติสินเชื่อกี่วัน', 'a' => 'โดยเฉลี่ยธนาคารใช้เวลาพิจารณา 7-14 วันทำการ ขึ้นกับความครบถ้วนของเอกสารที่ยื่น', 'category' => 'finance', 'tags' => 'สินเชื่อ,ระยะเวลา'],
        ];

        foreach ($faqs as $faq) {
            Faq::firstOrCreate(
                ['question_th' => $faq['q']],
                ['answer_th' => $faq['a'], 'category' => $faq['category'], 'tags' => $faq['tags'], 'is_active' => true],
            );
        }
    }

    private function seedKnowledge(): void
    {
        $entries = [
            ['title' => 'นโยบายการตอบข้อมูลรายการ', 'body' => 'ให้ยืนยันความพร้อมและราคาเฉพาะจากรายการที่เผยแพร่ในระบบ หากต้องการต่อรอง นัดหมาย หรือชำระเงิน ให้ส่งต่อทีมเจ้าหน้าที่', 'type' => 'policy', 'category' => 'sales', 'tags' => 'ราคา,availability,handoff'],
            ['title' => 'ขั้นตอนการฝากขายทรัพย์แบบละเอียด', 'body' => "1. ติดต่อทีมงานพร้อมแจ้งประเภททรัพย์และทำเล\n2. นัดสำรวจและถ่ายภาพทรัพย์\n3. ทีมงานประเมินราคาตลาดและเสนอกลยุทธ์การขาย\n4. เซ็นสัญญาฝากขาย ระบุระยะเวลาและค่าคอมมิชชั่น\n5. เริ่มทำการตลาดผ่านช่องทางออนไลน์และเครือข่ายทีมงาน\n6. ทีมงานนัดชมทรัพย์กับผู้สนใจและอัปเดตความคืบหน้าเป็นระยะ", 'type' => 'process', 'category' => 'sales', 'tags' => 'ฝากขาย,ขั้นตอน'],
            ['title' => 'ขั้นตอนการซื้อขายอสังหาริมทรัพย์', 'body' => "1. เลือกรายการที่สนใจและนัดชมทรัพย์\n2. ตกลงราคาและวางเงินมัดจำ/เงินจอง\n3. เตรียมเอกสารและยื่นขอสินเชื่อ (ถ้าต้องการ)\n4. รอผลอนุมัติสินเชื่อจากธนาคาร\n5. นัดวันโอนกรรมสิทธิ์ที่สำนักงานที่ดิน\n6. ชำระค่าธรรมเนียมและภาษีที่เกี่ยวข้อง\n7. รับโอนกรรมสิทธิ์และกุญแจทรัพย์", 'type' => 'process', 'category' => 'sales', 'tags' => 'ซื้อขาย,ขั้นตอน'],
            ['title' => 'ขั้นตอนการเช่าอสังหาริมทรัพย์', 'body' => "1. เลือกรายการที่สนใจและนัดชมทรัพย์\n2. ตกลงเงื่อนไขการเช่าและระยะเวลาสัญญา\n3. วางมัดจำและค่าเช่าล่วงหน้าตามที่ตกลง\n4. เซ็นสัญญาเช่าและรับกุญแจ\n5. ทีมงานบันทึกสภาพทรัพย์ ณ วันรับมอบเพื่อใช้อ้างอิงตอนคืนห้อง", 'type' => 'process', 'category' => 'rent', 'tags' => 'เช่า,ขั้นตอน'],
            ['title' => 'นโยบายค่าคอมมิชชั่นและการชำระเงิน', 'body' => 'ค่าคอมมิชชั่นมาตรฐานสำหรับการฝากขายอยู่ที่ 3% ของราคาขาย ชำระหลังจากการซื้อขายเสร็จสมบูรณ์และโอนกรรมสิทธิ์แล้วเท่านั้น ไม่มีการเรียกเก็บล่วงหน้า', 'type' => 'policy', 'category' => 'sales', 'tags' => 'ค่าคอม,นโยบาย'],
            ['title' => 'นโยบายการคืนเงินมัดจำ', 'body' => 'เงินมัดจำจะคืนเต็มจำนวนหากผู้ซื้อ/ผู้เช่ายื่นขอสินเชื่อไม่ผ่านตามเงื่อนไขในสัญญา หรือหากฝ่ายผู้ขาย/เจ้าของทรัพย์ยกเลิกสัญญาเอง หากผู้ซื้อ/ผู้เช่ายกเลิกโดยไม่มีเหตุผลตามสัญญา เงินมัดจำอาจไม่คืนตามเงื่อนไขที่ระบุไว้', 'type' => 'policy', 'category' => 'transaction', 'tags' => 'มัดจำ,คืนเงิน'],
            ['title' => 'เอกสารที่ต้องเตรียมแยกตามบทบาท', 'body' => "ผู้ซื้อ/ผู้เช่า: บัตรประชาชน ทะเบียนบ้าน หลักฐานรายได้\nผู้ขาย/เจ้าของทรัพย์: สำเนาโฉนด บัตรประชาชน หนังสือมอบอำนาจ (ถ้ามี)\nนิติบุคคล: หนังสือรับรองบริษัท มติที่ประชุมกรรมการ\nชาวต่างชาติ: เพิ่มเติมหลักฐานการโอนเงินจากต่างประเทศ (สำหรับซื้อคอนโด)", 'type' => 'reference', 'category' => 'documents', 'tags' => 'เอกสาร,รายบุคคล'],
            ['title' => 'พื้นที่ให้บริการ', 'body' => 'ให้บริการหลักในกรุงเทพมหานครฝั่งตะวันออก (บางนา ลาดพร้าว สุขุมวิท บางกะปิ) จังหวัดนนทบุรี (ปากเกร็ด บางใหญ่ บางบัวทอง) และจังหวัดปทุมธานี (ลำลูกกา ธัญบุรี) พื้นที่นอกเหนือจากนี้สามารถสอบถามได้ อาจใช้เวลาประสานงานเพิ่มเติม', 'type' => 'general', 'category' => 'general', 'tags' => 'พื้นที่บริการ'],
            ['title' => 'เวลาทำการและช่องทางติดต่อ', 'body' => 'ทีมงานให้บริการทุกวัน 09:00-19:00 น. นอกเวลาทำการ AI ยังคงตอบคำถามเบื้องต้นได้ตลอด 24 ชั่วโมง ช่องทางหลักคือแชท LINE นี้ หากต้องการให้เจ้าหน้าที่โทรกลับ สามารถฝากเบอร์โทรไว้ในแชทได้', 'type' => 'general', 'category' => 'general', 'tags' => 'เวลาทำการ,ติดต่อ'],
            ['title' => 'นโยบายความเป็นส่วนตัวของลูกค้า', 'body' => 'ข้อมูลลูกค้าที่ได้รับผ่านแชท (ชื่อ เบอร์โทร ความสนใจ) ใช้เพื่อการติดต่อประสานงานเรื่องอสังหาริมทรัพย์เท่านั้น ไม่นำไปใช้เพื่อวัตถุประสงค์อื่นหรือส่งต่อให้บุคคลภายนอกโดยไม่ได้รับความยินยอม', 'type' => 'policy', 'category' => 'privacy', 'tags' => 'ความเป็นส่วนตัว'],
            ['title' => 'ขั้นตอนการนัดชมทรัพย์', 'body' => 'แจ้งรหัสรายการที่สนใจพร้อมวันเวลาที่สะดวกกับทีมงาน ทีมงานจะยืนยันนัดหมายล่วงหน้าอย่างน้อย 1 วัน และส่งตำแหน่งที่ตั้งพร้อมข้อมูลผู้ติดต่อ ณ สถานที่ให้ก่อนวันนัด', 'type' => 'process', 'category' => 'sales', 'tags' => 'นัดชม'],
            ['title' => 'เงื่อนไขพิเศษสำหรับนักลงทุน', 'body' => 'นักลงทุนที่สนใจซื้อหลายรายการหรือลงทุนระยะยาว สามารถขอข้อมูลผลตอบแทนโดยประมาณ (yield) และแนวโน้มราคาของแต่ละทำเลจากทีมงานได้ ทุกตัวเลขเป็นการประมาณการจากข้อมูลตลาดปัจจุบัน ไม่ใช่การรับประกันผลตอบแทน', 'type' => 'reference', 'category' => 'investment', 'tags' => 'นักลงทุน,ผลตอบแทน'],
        ];

        foreach ($entries as $entry) {
            KnowledgeEntry::firstOrCreate(
                ['title' => $entry['title']],
                ['body' => $entry['body'], 'type' => $entry['type'], 'category' => $entry['category'], 'tags' => $entry['tags'], 'version' => 1, 'is_active' => true, 'reviewed_at' => now()],
            );
        }
    }
}
