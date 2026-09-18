<?php

namespace Tests\Feature;

use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'packages').'.csv';
        file_put_contents($path, collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n"));

        return new UploadedFile($path, 'packages.csv', 'text/csv', null, true);
    }

    private function xlsxUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'properties').'.xlsx';
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'properties.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    public function test_preview_does_not_write_to_database_and_classifies_rows(): void
    {
        Storage::fake('local');
        ServicePackage::factory()->create(['code' => 'EXISTING-001', 'price' => 999]);

        $rows = [
            ['code', 'name_th', 'description_th', 'price', 'sale_price', 'effective_from', 'effective_until', 'terms', 'keywords'],
            ['EXISTING-001', 'รายการเดิม', '', '1', '', '', '', '', ''],
            ['PROMO-NEW-001', 'โปรโมชันใหม่', 'รายละเอียด', '1500', '1290', '2026-07-01', '2026-12-31', 'เงื่อนไข', 'โปรโมชัน'],
            ['', 'ไม่มีรหัส', '', '100', '', '', '', '', ''],
        ];

        $response = $this->actingAs($this->admin())
            ->post('/admin/imports/packages/preview', ['file' => $this->csvUpload($rows)]);

        $response->assertRedirect('/admin/imports')
            ->assertSessionHas('package_import_preview', fn (array $preview) =>
                $preview['new_count'] === 1
                && $preview['duplicate_count'] === 1
                && $preview['invalid_count'] === 1
            );

        $this->assertDatabaseCount('packages', 1);
        $this->assertDatabaseCount('import_records', 0);
    }

    public function test_confirm_adds_only_new_rows_as_drafts_and_never_overwrites_duplicate_code(): void
    {
        Storage::fake('local');
        ServicePackage::factory()->create(['code' => 'EXISTING-001', 'name_th' => 'ข้อมูลเดิม', 'price' => 999]);

        $rows = [
            ['code', 'name_th', 'description_th', 'price', 'sale_price', 'effective_from', 'effective_until', 'terms', 'keywords'],
            ['EXISTING-001', 'ห้ามทับ', '', '1', '', '', '', '', ''],
            ['PROMO-NEW-001', 'โปรโมชันใหม่', 'รายละเอียด', '1500', '1290', '2026-07-01', '2026-12-31', 'เงื่อนไข', 'โปรโมชัน'],
        ];

        $this->actingAs($this->admin())
            ->post('/admin/imports/packages/preview', ['file' => $this->csvUpload($rows)])
            ->assertSessionHas('package_import_preview');

        $preview = session('package_import_preview');

        $this->post('/admin/imports/packages/confirm', ['token' => $preview['token']])
            ->assertRedirect('/admin/imports')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('packages', [
            'code' => 'EXISTING-001',
            'name_th' => 'ข้อมูลเดิม',
            'price' => 999,
        ]);
        $this->assertDatabaseHas('packages', [
            'code' => 'PROMO-NEW-001',
            'is_active' => true,
            'is_published' => false,
        ]);
        $this->assertDatabaseCount('packages', 2);
        $this->assertDatabaseHas('import_records', [
            'resource' => 'packages',
            'rows_imported' => 1,
            'rows_skipped' => 1,
            'rows_failed' => 0,
        ]);
    }

    public function test_preview_requires_a_file(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/imports')
            ->post('/admin/imports/packages/preview')
            ->assertSessionHasErrors('file');
    }

    public function test_property_template_import_persists_structured_search_fields_as_a_draft(): void
    {
        Storage::fake('local');

        $rows = [
            [
                'code', 'category_slug', 'transaction_type', 'availability', 'name_th',
                'description_th', 'price', 'sale_price', 'location_text', 'province',
                'district', 'subdistrict', 'project_name', 'bedrooms', 'bathrooms',
                'usable_area_sqm', 'land_area_sqw', 'floor', 'primary_image_url',
                'effective_from', 'effective_until', 'terms', 'keywords',
            ],
            [
                'CONDO-001', 'condo', 'sale', 'available', 'คอนโดสุขุมวิท 2 ห้องนอน',
                'ใกล้รถไฟฟ้า', '7900000', '', 'ทองหล่อ', 'กรุงเทพมหานคร',
                'วัฒนา', 'คลองตันเหนือ', 'Lean Residence', '2', '2',
                '68', '', '12', 'https://cdn.example.com/properties/condo-001.jpg',
                '', '', 'ข้อมูลตัวอย่าง', 'สุขุมวิท ทองหล่อ',
            ],
        ];

        \App\Models\PackageCategory::factory()->create(['slug' => 'condo']);

        $this->actingAs($this->admin())
            ->post('/admin/imports/packages/preview', ['file' => $this->xlsxUpload($rows)])
            ->assertSessionHas('package_import_preview');

        $preview = session('package_import_preview');
        $this->assertSame(1, $preview['new_count']);

        $this->post('/admin/imports/packages/confirm', ['token' => $preview['token']])
            ->assertRedirect('/admin/imports');

        $this->assertDatabaseHas('packages', [
            'code' => 'CONDO-001',
            'item_type' => 'property',
            'transaction_type' => 'sale',
            'availability' => 'available',
            'location_text' => 'ทองหล่อ',
            'project_name' => 'Lean Residence',
            'bedrooms' => 2,
            'primary_image_url' => 'https://cdn.example.com/properties/condo-001.jpg',
            'is_published' => false,
        ]);
    }

    public function test_faq_and_knowledge_import_routes_do_not_exist(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/imports/faqs')->assertNotFound();
        $this->actingAs($admin)->post('/admin/imports/knowledge')->assertNotFound();
    }

    public function test_viewer_cannot_preview_or_confirm_imports(): void
    {
        $viewer = User::factory()->create(['is_admin' => false, 'role' => 'viewer']);
        $rows = [
            ['code', 'name_th', 'description_th', 'price', 'sale_price', 'effective_from', 'effective_until', 'terms', 'keywords'],
            ['PROMO-001', 'โปรโมชันใหม่', '', '100', '', '', '', '', ''],
        ];

        $this->actingAs($viewer)
            ->post('/admin/imports/packages/preview', ['file' => $this->csvUpload($rows)])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/admin/imports/packages/confirm', ['token' => (string) \Illuminate\Support\Str::uuid()])
            ->assertForbidden();
    }
}
