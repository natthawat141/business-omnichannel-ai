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
}
