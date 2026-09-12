<?php

namespace Tests\Feature;

use App\Models\PackageCategory;
use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TypedCatalogAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function definition(string $key = 'area_range', string $type = 'numeric_range'): array
    {
        return ['key' => $key, 'label' => 'พื้นที่ตัวอย่าง', 'type' => $type,
            'unit' => $type === 'numeric_range' ? 'sqm' : null, 'nullable' => true,
            'allowed_values' => $type === 'enum' ? ['a', 'b'] : [],
            'searchable' => false, 'allowed_operators' => []];
    }

    private function category(array $definitions = []): PackageCategory
    {
        return PackageCategory::factory()->create(['attribute_definitions' => $definitions ?: [$this->definition()]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_admin_saves_typed_range_without_flattening(): void
    {
        $category = $this->category();
        $range = ['min' => 34.5, 'max' => 48, 'unit' => 'sqm'];
        $this->actingAs($this->admin())->postJson('/admin/packages', [
            'name_th' => 'ตัวอย่างประเภทห้อง', 'category_id' => $category->id,
            'attributes' => ['area_range' => $range],
        ])->assertRedirect('/admin/packages');
        // MySQL normalizes object key order; the values and their JSON types must survive.
        $actual = ServicePackage::first()->attributes['area_range'];
        $this->assertEquals($range, $actual);
        $this->assertSame(34.5, $actual['min']);
        $this->assertSame(48, $actual['max']);
        $this->assertSame(2, $category->fresh()->schema_version);
    }

    public function test_admin_definition_save_is_versioned_by_server(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/package-categories', [
            'name_th' => 'หมวดทดสอบ', 'slug' => 'typed-fixture', 'schema_version' => 900,
            'attribute_definitions' => [$this->definition()],
        ])->assertRedirect();
        $category = PackageCategory::where('slug', 'typed-fixture')->firstOrFail();
        $this->assertEquals([$this->definition()], $category->attribute_definitions);
        $this->assertSame(2, $category->schema_version);
        $category->update(['name_th' => 'ชื่อใหม่']);
        $this->assertSame(2, $category->fresh()->schema_version);
        $category->update(['attribute_definitions' => [$this->definition(), $this->definition('view', 'string')]]);
        $this->assertSame(3, $category->fresh()->schema_version);
    }

    public static function invalidValues(): array
    {
        return [
            'wrong unit' => [['area_range' => ['min' => 1, 'max' => 2, 'unit' => 'sqw']], 'attributes.area_range'],
            'inverted range' => [['area_range' => ['min' => 3, 'max' => 2, 'unit' => 'sqm']], 'attributes.area_range'],
            'numeric string' => [['area_range' => ['min' => '1', 'max' => 2, 'unit' => 'sqm']], 'attributes.area_range'],
            'extra nested key' => [['area_range' => ['min' => 1, 'max' => 2, 'unit' => 'sqm', 'sql' => 'x']], 'attributes.area_range'],
            'unregistered' => [['surprise' => 'x'], 'attributes.surprise'],
            'duplicate core fact' => [['price' => 100], 'attributes.price'],
            'list not map' => [['x'], 'attributes'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_typed_values_are_rejected(array $values, string $field): void
    {
        $category = $this->category();
        $this->actingAs($this->admin())->postJson('/admin/packages', [
            'name_th' => 'ตัวอย่าง', 'category_id' => $category->id, 'attributes' => $values,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('packages', 0);
    }

    public static function scalarCases(): array
    {
        return [
            'string' => ['string', 'วิวสวน', 1],
            'integer' => ['integer', 4, '4'],
            'decimal' => ['decimal', 4.5, '4.5'],
            'boolean' => ['boolean', false, 'false'],
            'enum' => ['enum', 'a', 'c'],
            'string_list' => ['string_list', ['สวน', 'สระว่ายน้ำ'], ['สวน', 3]],
        ];
    }

    #[DataProvider('scalarCases')]
    public function test_types_are_preserved_and_not_coerced(string $type, mixed $valid, mixed $invalid): void
    {
        $category = $this->category([$this->definition('value', $type)]);
        $item = ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['value' => $valid]]);
        $this->assertSame($valid, $item->fresh()->attributes['value']);
        $this->expectException(ValidationException::class);
        $item->update(['attributes' => ['value' => $invalid]]);
    }

    public function test_null_is_explicit_and_omitted_update_preserves_existing_attributes(): void
    {
        $category = $this->category();
        $item = ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['area_range' => null]]);
        $this->actingAs($this->admin())->putJson('/admin/packages/'.$item->id, [
            'name_th' => 'เปลี่ยนเฉพาะชื่อ',
        ])->assertRedirect();
        $this->assertSame(['area_range' => null], $item->fresh()->attributes);
    }

    public function test_non_nullable_rejects_explicit_null_but_does_not_invent_missing_values(): void
    {
        $definition = $this->definition('view', 'string');
        $definition['nullable'] = false;
        $category = $this->category([$definition]);
        ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => []]);
        $this->expectException(ValidationException::class);
        ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['view' => null]]);
    }

    public function test_unknown_keys_are_rejected_even_without_http_request(): void
    {
        $category = $this->category();
        $this->expectException(ValidationException::class);
        ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['rogue' => 'x']]);
    }

    public function test_definition_cannot_shadow_core_fields(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/package-categories', [
            'name_th' => 'ไม่ถูกต้อง', 'attribute_definitions' => [$this->definition('price', 'decimal')],
        ])->assertUnprocessable()->assertJsonValidationErrors('attribute_definitions.0.key');
    }

    public function test_definition_cannot_enable_arbitrary_operators(): void
    {
        $definition = $this->definition();
        $definition['searchable'] = true;
        $definition['allowed_operators'] = ['raw_sql'];
        $this->actingAs($this->admin())->postJson('/admin/package-categories', [
            'name_th' => 'ไม่ถูกต้อง', 'attribute_definitions' => [$definition],
        ])->assertUnprocessable()->assertJsonValidationErrors('attribute_definitions.0.allowed_operators');
    }

    public function test_existing_typed_definitions_cannot_be_removed_when_records_exist(): void
    {
        $category = $this->category();
        ServicePackage::factory()->create(['category_id' => $category->id]);
        $this->expectException(ValidationException::class);
        $category->update(['attribute_definitions' => []]);
    }

    public function test_legacy_attributes_are_not_silently_converted_when_activating_typed_schema(): void
    {
        $category = PackageCategory::factory()->create();
        $item = ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['parking' => '2 คัน']]);
        try {
            $category->update(['attribute_definitions' => [$this->definition('parking', 'integer')]]);
            $this->fail('Populated legacy attributes need an explicit conversion review.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attribute_definitions', $exception->errors());
        }
        $this->assertSame(1, $category->fresh()->schema_version);
        $this->assertSame(['parking' => '2 คัน'], $item->fresh()->attributes);
    }

    public function test_legacy_string_form_stays_compatible(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/packages', [
            'name_th' => 'รายการเดิม', 'attributes' => '{"parking":"2 คัน"}',
        ])->assertRedirect();
        $item = ServicePackage::firstOrFail();
        $this->assertSame('available', $item->availability);
        $this->assertSame(['parking' => '2 คัน'], $item->attributes);
    }

    public function test_existing_legacy_core_descriptors_remain_unchanged(): void
    {
        $definitions = [['key' => 'bedrooms', 'label_th' => 'ห้องนอน', 'type' => 'number',
            'operators' => ['eq', 'gte', 'lte'], 'searchable' => true]];
        $category = PackageCategory::factory()->create(['attribute_definitions' => $definitions]);
        $category->update(['name_th' => 'แก้เฉพาะชื่อ']);
        $this->assertSame(1, $category->fresh()->schema_version);
        $this->assertEquals($definitions, $category->fresh()->attribute_definitions);
        // Public admin input cannot use the seed-only legacy descriptor compatibility branch.
        $this->actingAs($this->admin())->postJson('/admin/package-categories', [
            'name_th' => 'หมวดใหม่', 'attribute_definitions' => $definitions,
        ])->assertUnprocessable();
    }

    public function test_legacy_thai_attribute_keys_remain_compatible(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/packages', [
            'name_th' => 'รายการเดิม', 'attributes' => ['ที่จอดรถ' => '2 คัน'],
        ])->assertRedirect();
    }

    public function test_typed_category_with_records_cannot_be_deleted(): void
    {
        $category = $this->category();
        ServicePackage::factory()->create(['category_id' => $category->id]);
        $this->actingAs($this->admin())->deleteJson('/admin/package-categories/'.$category->id)
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->assertDatabaseHas('package_categories', ['id' => $category->id]);
    }

    public function test_whole_attributes_null_cannot_bypass_non_nullable_values(): void
    {
        $definition = $this->definition('view', 'string');
        $definition['nullable'] = false;
        $category = $this->category([$definition]);
        // Null clears the whole optional attribute map, rather than inventing a null value for each field.
        $item = ServicePackage::factory()->create(['category_id' => $category->id, 'attributes' => ['view' => 'garden']]);
        $item->update(['attributes' => null]);
        $this->assertNull($item->fresh()->attributes);
    }

    public function test_stale_category_cannot_overwrite_a_newer_schema(): void
    {
        $category = $this->category();
        $stale = $category->fresh();
        $category->update(['attribute_definitions' => [$this->definition(), $this->definition('view', 'string')]]);
        try {
            $stale->update(['attribute_definitions' => [$this->definition(), $this->definition('parking', 'integer')]]);
            $this->fail('Stale definition update must conflict.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertSame(3, $category->fresh()->schema_version);
            $this->assertSame('view', $category->fresh()->attribute_definitions[1]['key']);
        }
    }

    public function test_editor_cannot_activate_schema_but_can_edit_a_category_name(): void
    {
        $category = PackageCategory::factory()->create();
        $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => 'editor']))
            ->putJson('/admin/package-categories/'.$category->id, [
                'name_th' => 'ชื่อที่แก้ได้', 'attribute_definitions' => [$this->definition()],
            ])->assertForbidden();
        $this->putJson('/admin/package-categories/'.$category->id, ['name_th' => 'ชื่อที่แก้ได้'])->assertRedirect();
        $this->assertSame(1, $category->fresh()->schema_version);
    }

    public static function malformedDefinitions(): array
    {
        return [
            'object not list' => [['key' => 'x']],
            'nested type' => [[['key' => 'value', 'type' => ['integer']]]],
            'sql field' => [[['key' => 'x', 'type' => 'sql']]],
            'duplicate' => [[
                ['key' => 'x', 'type' => 'integer', 'label' => 'x', 'nullable' => true, 'searchable' => false],
                ['key' => 'x', 'type' => 'integer', 'label' => 'x', 'nullable' => true, 'searchable' => false],
            ]],
            'nested enum option' => [[['key' => 'x', 'type' => 'enum', 'label' => 'x', 'nullable' => true,
                'searchable' => false, 'allowed_values' => [['value' => 'x']]]]],
        ];
    }

    #[DataProvider('malformedDefinitions')]
    public function test_malformed_definitions_fail_without_server_errors(array $definitions): void
    {
        $this->actingAs($this->admin())->postJson('/admin/package-categories', [
            'name_th' => 'ไม่ถูกต้อง', 'attribute_definitions' => $definitions,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('package_categories', 0);
    }
}
