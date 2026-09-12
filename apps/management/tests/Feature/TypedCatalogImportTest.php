<?php

namespace Tests\Feature;

use App\Exports\PackagesExport;
use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\PackageImportPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TypedCatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private function category(): PackageCategory
    {
        return PackageCategory::factory()->create(['slug' => 'typed-fixture', 'attribute_definitions' => [
            ['key' => 'area_range', 'label' => 'พื้นที่', 'type' => 'numeric_range', 'unit' => 'sqm',
                'nullable' => true, 'searchable' => false, 'allowed_values' => [], 'allowed_operators' => []],
        ]]);
    }

    private function upload(array $values): UploadedFile
    {
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, [...array_slice(PackageImportPreview::COLUMNS, 0, 23), 'attributes'], escape: '');
        $row = array_fill(0, 23, '');
        $row[0] = 'TYPED-001';
        $row[1] = 'typed-fixture';
        $row[4] = 'ตัวอย่าง';
        fputcsv($stream, [...$row, json_encode($values, JSON_UNESCAPED_UNICODE)], escape: '');
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return UploadedFile::fake()->createWithContent('typed.csv', $content);
    }

    public function test_preview_and_confirm_preserve_typed_json_as_a_draft(): void
    {
        Storage::fake('local');
        $this->category();
        $values = ['area_range' => ['min' => 34.5, 'max' => 48, 'unit' => 'sqm']];
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/imports/packages/preview', ['file' => $this->upload($values)])
            ->assertSessionHas('package_import_preview', fn ($p) => is_array($p) && $p['new_count'] === 1 && $p['invalid_count'] === 0);
        $token = session('package_import_preview.token');
        $this->assertDatabaseCount('packages', 0);
        $this->post('/admin/imports/packages/confirm', ['token' => $token])->assertSessionHas('success');
        $item = ServicePackage::where('code', 'TYPED-001')->firstOrFail();
        $this->assertEquals($values, $item->attributes);
        $this->assertSame(34.5, $item->attributes['area_range']['min']);
        $this->assertSame(48, $item->attributes['area_range']['max']);
        $this->assertFalse($item->is_published);
        $export = new PackagesExport;
        $row = array_combine($export->headings(), $export->map($item));
        $decoded = json_decode($row['attributes'], true);
        $this->assertEquals($values, $decoded);
        $this->assertSame(34.5, $decoded['area_range']['min']);
        $this->assertSame(48, $decoded['area_range']['max']);
    }

    public function test_invalid_range_is_rejected_in_both_preview_and_confirmation(): void
    {
        Storage::fake('local');
        $this->category();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/imports/packages/preview', ['file' => $this->upload([
                'area_range' => ['min' => 50, 'max' => 48, 'unit' => 'sqm'],
            ])])->assertSessionHas('package_import_preview', fn ($p) => is_array($p) && $p['invalid_count'] === 1 && $p['new_count'] === 0);
        $this->post('/admin/imports/packages/confirm', ['token' => session('package_import_preview.token')]);
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_export_includes_attributes_json_column(): void
    {
        $category = $this->category();
        $item = ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['area_range' => null]]);
        $export = new PackagesExport;
        $this->assertContains('attributes', $export->headings());
        $row = array_combine($export->headings(), $export->map($item));
        $this->assertSame(['area_range' => null], json_decode($row['attributes'], true));
    }

    public function test_preview_failure_does_not_log_raw_exception_or_filename(): void
    {
        Storage::fake('local');
        \Illuminate\Support\Facades\Log::spy();
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->post('/admin/imports/packages/preview', ['file' => UploadedFile::fake()->createWithContent('private-fixture.csv', "invalid\nheader")])
            ->assertRedirect('/admin/imports')->assertSessionHas('error');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('Package import preview failed', [
            'failure_class' => \RuntimeException::class,
        ]);
    }

    public function test_viewer_cannot_confirm_an_import(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => 'viewer', 'is_active' => true]))
            ->postJson('/admin/imports/packages/confirm', ['token' => '00000000-0000-4000-8000-000000000000'])
            ->assertForbidden();
    }
}
