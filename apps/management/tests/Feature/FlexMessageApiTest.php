<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexMessageApiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        ['plainText' => $plainText] = ApiToken::issue('test-token', ['read']);

        return ['Authorization' => 'Bearer '.$plainText];
    }

    public function test_it_returns_property_flex_bubble(): void
    {
        $category = PackageCategory::create([
            'slug' => 'condo',
            'name_th' => 'คอนโดมิเนียม',
            'name_en' => 'Condominium',
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'code' => 'TEST-001',
            'name_th' => 'The Base บางนา',
            'category_id' => $category->id,
            'item_type' => 'property',
            'transaction_type' => 'sale',
            'availability' => 'available',
            'price' => 2500000,
            'is_active' => true,
            'is_published' => true,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'usable_area_sqm' => 32,
        ]);

        $res = $this->getJson("/api/v1/flex/catalog/{$package->id}", $this->auth())
            ->assertOk();

        $res->assertJsonPath('type', 'flex');
        $res->assertJsonPath('contents.type', 'bubble');
        $res->assertJsonPath('contents.body.contents.0.text', '฿2,500,000');
    }

    public function test_it_returns_carousel_flex(): void
    {
        $category = PackageCategory::create([
            'slug' => 'condo',
            'name_th' => 'คอนโดมิเนียม',
            'name_en' => 'Condominium',
            'is_active' => true,
        ]);

        ServicePackage::create([
            'code' => 'TEST-001',
            'name_th' => 'The Base บางนา',
            'category_id' => $category->id,
            'item_type' => 'property',
            'transaction_type' => 'sale',
            'availability' => 'available',
            'price' => 2500000,
            'is_active' => true,
            'is_published' => true,
        ]);

        $res = $this->getJson('/api/v1/flex/carousel?category_slug=condo', $this->auth())
            ->assertOk();

        $res->assertJsonPath('type', 'flex');
        $res->assertJsonPath('contents.type', 'carousel');
    }

    public function test_it_builds_carousel_from_exact_item_ids_in_requested_order(): void
    {
        $category = PackageCategory::factory()->create(['slug' => 'condo']);
        $first = ServicePackage::factory()->create([
            'category_id' => $category->id,
            'name_th' => 'ทรัพย์ลำดับแรก',
            'availability' => 'available',
        ]);
        $second = ServicePackage::factory()->create([
            'category_id' => $category->id,
            'name_th' => 'ทรัพย์ลำดับสอง',
            'availability' => 'available',
        ]);

        $this->postJson('/api/v1/flex/carousel', [
            'item_ids' => [$second->id, $first->id],
        ], $this->auth())
            ->assertOk()
            ->assertJsonPath('contents.contents.0.header.contents.1.text', 'ทรัพย์ลำดับสอง')
            ->assertJsonPath('contents.contents.1.header.contents.1.text', 'ทรัพย์ลำดับแรก');
    }

    public function test_exact_item_carousel_excludes_ineligible_properties(): void
    {
        $eligible = ServicePackage::factory()->create([
            'name_th' => 'ทรัพย์ที่พร้อมเสนอ',
            'availability' => 'available',
        ]);
        $unavailable = ServicePackage::factory()->create(['availability' => 'unavailable']);
        $expired = ServicePackage::factory()->create([
            'availability' => 'available',
            'effective_until' => now()->subDay()->toDateString(),
        ]);

        $this->postJson('/api/v1/flex/carousel', [
            'item_ids' => [$unavailable->id, $eligible->id, $expired->id],
        ], $this->auth())
            ->assertOk()
            ->assertJsonCount(1, 'contents.contents')
            ->assertJsonPath('contents.contents.0.header.contents.1.text', 'ทรัพย์ที่พร้อมเสนอ');
    }

    public function test_exact_item_carousel_rejects_an_empty_item_list(): void
    {
        $this->postJson('/api/v1/flex/carousel', ['item_ids' => []], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_ids');
    }

    public function test_single_property_flex_rejects_an_unavailable_property(): void
    {
        $package = ServicePackage::factory()->create(['availability' => 'unavailable']);

        $this->getJson("/api/v1/flex/catalog/{$package->id}", $this->auth())
            ->assertNotFound();
    }

    public function test_property_flex_uses_the_primary_image_as_its_hero(): void
    {
        $package = ServicePackage::factory()->create([
            'availability' => 'available',
            'primary_image_url' => 'https://cdn.example.com/properties/listing-001.jpg',
        ]);

        $this->getJson("/api/v1/flex/catalog/{$package->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('contents.hero.type', 'image')
            ->assertJsonPath('contents.hero.url', 'https://cdn.example.com/properties/listing-001.jpg');
    }

    public function test_property_flex_does_not_invent_features_when_specs_are_empty(): void
    {
        $package = ServicePackage::factory()->create([
            'availability' => 'available',
            'bedrooms' => null,
            'bathrooms' => null,
            'usable_area_sqm' => null,
            'land_area_sqw' => null,
        ]);

        $this->getJson("/api/v1/flex/catalog/{$package->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath(
                'contents.body.contents.1.contents.1.text',
                'สอบถามรายละเอียดเพิ่มเติม',
            );
    }

    public function test_property_flex_handles_null_price_gracefully(): void
    {
        $package = ServicePackage::factory()->create([
            'availability' => 'available',
            'price' => null,
            'name_th' => 'ที่ดินเปล่าแปลงพิเศษ',
        ]);

        $res = $this->getJson("/api/v1/flex/catalog/{$package->id}", $this->auth())
            ->assertOk();

        $res->assertJsonPath('altText', '🏡 ที่ดินเปล่าแปลงพิเศษ');
        $res->assertJsonPath('contents.body.contents.0.text', 'ติดต่อสอบถาม');
    }
}
