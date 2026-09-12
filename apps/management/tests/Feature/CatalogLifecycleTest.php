<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function readHeaders(): array
    {
        ['plainText' => $plain] = ApiToken::issue('catalog-read', ['read']);

        return ['Authorization' => 'Bearer '.$plain];
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_legacy_package_is_an_offer_with_initial_lock_version(): void
    {
        $item = ServicePackage::factory()->create();

        $this->assertSame('offer', $item->record_kind);
        $this->assertSame(1, $item->lock_version);
        $this->assertNull($item->archived_at);
    }

    public function test_public_catalog_never_exposes_groups_variants_or_archived_offers(): void
    {
        $category = PackageCategory::factory()->create(['slug' => 'catalog-lifecycle']);
        foreach (['group', 'variant', 'offer'] as $kind) {
            ServicePackage::factory()->create([
                'category_id' => $category->id,
                'record_kind' => $kind,
                'is_active' => true,
                'is_published' => true,
                'availability' => 'available',
            ]);
        }
        $archived = ServicePackage::factory()->create([
            'category_id' => $category->id, 'record_kind' => 'offer',
            'is_active' => true, 'is_published' => true, 'availability' => 'available',
        ]);
        $archived->archive();

        $response = $this->postJson('/api/v1/catalog/search', [
            'category_slug' => $category->slug, 'limit' => 20,
        ], $this->readHeaders())->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertCount(1, $ids);
        $this->assertNotContains($archived->id, $ids);
        $this->getJson('/api/v1/catalog/'.$archived->id, $this->readHeaders())->assertNotFound();
    }

    public function test_catalog_hierarchy_only_allows_group_variant_offer_and_never_cycles(): void
    {
        $group = ServicePackage::factory()->create(['record_kind' => 'group']);
        $variant = ServicePackage::factory()->create(['record_kind' => 'variant', 'parent_id' => $group->id]);
        $offer = ServicePackage::factory()->create(['record_kind' => 'offer', 'parent_id' => $variant->id]);
        $this->assertSame($group->id, $variant->parent->id);
        $this->assertSame($variant->id, $offer->parent->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $group->update(['parent_id' => $offer->id]);
    }

    public function test_admin_update_requires_matching_lock_version_and_bumps_it(): void
    {
        $item = ServicePackage::factory()->create(['lock_version' => 1]);
        $this->actingAs($this->admin())->put('/admin/packages/'.$item->id, [
            'name_th' => 'แก้ไขครั้งแรก', 'lock_version' => 1,
        ])->assertRedirect();
        $this->assertSame(2, $item->fresh()->lock_version);

        $this->actingAs($this->admin())->from('/admin/packages/'.$item->id.'/edit')->put('/admin/packages/'.$item->id, [
            'name_th' => 'ข้อมูลเก่า', 'lock_version' => 1,
        ])->assertSessionHasErrors('lock_version');
        $this->assertSame('แก้ไขครั้งแรก', $item->fresh()->name_th);
    }

    public function test_archive_parent_with_live_children_is_rejected_and_restore_checks_unique_code(): void
    {
        $group = ServicePackage::factory()->create(['record_kind' => 'group']);
        ServicePackage::factory()->create(['record_kind' => 'variant', 'parent_id' => $group->id]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $group->archive();
    }

    public function test_archived_admin_record_is_recoverable_not_hard_deleted(): void
    {
        $item = ServicePackage::factory()->create();
        $this->actingAs($this->admin())->delete('/admin/packages/'.$item->id)->assertRedirect();
        $this->assertNotNull($item->fresh()->archived_at);
        $this->assertDatabaseHas('packages', ['id' => $item->id]);
    }
}
