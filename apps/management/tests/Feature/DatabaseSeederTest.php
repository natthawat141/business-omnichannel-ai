<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'SEED_DEMO_DATA'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    public function test_repeated_seeding_prefers_the_configured_email_when_multiple_admins_exist(): void
    {
        User::factory()->create([
            'email' => 'older-admin@example.com',
            'is_admin' => true,
        ]);
        User::factory()->create([
            'email' => 'owner@example.com',
            'is_admin' => true,
        ]);

        $this->setEnvironment('ADMIN_NAME', 'Configured Owner');
        $this->setEnvironment('ADMIN_EMAIL', 'owner@example.com');
        $this->setEnvironment('ADMIN_PASSWORD', 'test-password-not-secret');
        $this->setEnvironment('SEED_DEMO_DATA', 'false');

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', [
            'email' => 'owner@example.com',
            'name' => 'Configured Owner',
            'is_admin' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'older-admin@example.com',
            'is_admin' => true,
        ]);
    }

    private function setEnvironment(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public function test_demo_seeding_preserves_existing_business_profile(): void
    {
        $profile = \App\Models\BusinessProfile::current();
        $profile->update(['business_name' => 'Owner edited business']);
        $this->seed(\Database\Seeders\RealEstateDemoSeeder::class);
        $this->assertSame('Owner edited business', $profile->fresh()->business_name);
    }

    public function test_demo_reseed_preserves_edited_catalog_data(): void
    {
        $this->seed(\Database\Seeders\RealEstateDemoSeeder::class);
        $item = \App\Models\ServicePackage::where('code', 'DEMO-CONDO-001')->firstOrFail();
        $item->update(['name_th' => 'Owner edited catalog', 'price' => 12345]);
        $category = \App\Models\PackageCategory::where('slug', 'condo')->firstOrFail();
        $category->update(['name_th' => 'Owner edited category']);
        $this->seed(\Database\Seeders\RealEstateDemoSeeder::class);
        $this->assertSame('Owner edited catalog', $item->fresh()->name_th);
        $this->assertEquals(12345, $item->fresh()->price);
        $this->assertSame('Owner edited category', $category->fresh()->name_th);
    }

    public function test_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->app->make(\Database\Seeders\RealEstateDemoSeeder::class)->run();
            $this->fail('Demo seeding must not run in production');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Demo seeding is disabled in production.', $exception->getMessage());
            $this->assertSame(0, \App\Models\ServicePackage::count());
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
