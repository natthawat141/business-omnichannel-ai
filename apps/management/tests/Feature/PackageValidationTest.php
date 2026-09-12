<?php

namespace Tests\Feature;

use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_name_th_is_required(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/packages/create')
            ->post('/admin/packages', ['name_th' => '', 'currency' => 'THB'])
            ->assertSessionHasErrors('name_th');
    }

    public function test_effective_until_must_not_precede_effective_from(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/packages/create')
            ->post('/admin/packages', [
                'name_th' => 'แพ็กเกจทดสอบ',
                'effective_from' => '2026-07-01',
                'effective_until' => '2026-06-01',
            ])
            ->assertSessionHasErrors('effective_until');
    }

    public function test_it_creates_a_package_with_valid_data(): void
    {
        $category = PackageCategory::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/packages', [
                'name_th' => 'แพ็กเกจเลเซอร์',
                'category_id' => $category->id,
                'price' => 1500,
                'currency' => 'THB',
                'is_active' => true,
                'is_published' => true,
            ])
            ->assertRedirect('/admin/packages')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('packages', ['name_th' => 'แพ็กเกจเลเซอร์', 'price' => 1500]);
    }

    public function test_it_persists_an_https_primary_property_image(): void
    {
        $imageUrl = 'https://cdn.example.com/properties/listing-001.jpg';

        $this->actingAs($this->admin())
            ->post('/admin/packages', [
                'name_th' => 'คอนโดพร้อมรูป',
                'item_type' => 'property',
                'availability' => 'available',
                'primary_image_url' => $imageUrl,
            ])
            ->assertRedirect('/admin/packages');

        $this->assertSame($imageUrl, ServicePackage::query()->first()?->primary_image_url);
    }

    public function test_it_normalizes_a_google_drive_share_link_to_a_public_image_url(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/packages', [
                'name_th' => 'คอนโดพร้อมรูปจากไดรฟ์',
                'item_type' => 'property',
                'availability' => 'available',
                'primary_image_url' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/view?usp=sharing',
            ])
            ->assertRedirect('/admin/packages');

        $this->assertSame(
            'https://drive.usercontent.google.com/download?id=1AbCdEfGhIjKlMnOpQrStUvWxYz012345&export=view',
            ServicePackage::query()->first()?->primary_image_url,
        );
    }

    public function test_property_form_only_offers_file_upload_for_the_primary_image(): void
    {
        $form = file_get_contents(resource_path('js/pages/Packages/Form.tsx'));

        $this->assertIsString($form);
        $this->assertStringContainsString('type="file"', $form);
        $this->assertStringNotContainsString('วางลิงก์รูป', $form);
        $this->assertStringNotContainsString('Google Drive share link', $form);
        $this->assertStringNotContainsString('setImageLink', $form);
    }

    public function test_primary_property_image_rejects_non_https_urls(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/packages/create')
            ->post('/admin/packages', [
                'name_th' => 'คอนโดรูปไม่ปลอดภัย',
                'primary_image_url' => 'http://cdn.example.com/listing.jpg',
            ])
            ->assertSessionHasErrors('primary_image_url');
    }

    public function test_property_can_be_marked_sold(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/packages', [
                'name_th' => 'คอนโดขายแล้ว',
                'item_type' => 'property',
                'availability' => 'sold',
            ])
            ->assertRedirect('/admin/packages');

        $this->assertDatabaseHas('packages', [
            'name_th' => 'คอนโดขายแล้ว',
            'availability' => 'sold',
        ]);
    }

    public function test_property_can_be_marked_rented(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/packages', [
                'name_th' => 'คอนโดปล่อยเช่าแล้ว',
                'item_type' => 'property',
                'availability' => 'rented',
            ])
            ->assertRedirect('/admin/packages');

        $this->assertDatabaseHas('packages', [
            'name_th' => 'คอนโดปล่อยเช่าแล้ว',
            'availability' => 'rented',
        ]);
    }
}
