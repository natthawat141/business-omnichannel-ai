<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedAdmin();
        $this->call(AiServiceTokenSeeder::class);

        if (filter_var(env('SEED_DEMO_DATA') ?? config('services.admin.seed_demo_data', false), FILTER_VALIDATE_BOOL)) {
            $this->call(RealEstateDemoSeeder::class);
        }
    }

    /**
     * Create/refresh a single admin user from environment variables only.
     * Never hardcodes a real password. Skips gracefully if ADMIN_PASSWORD is unset.
     */
    private function seedAdmin(): void
    {
        $email = env('ADMIN_EMAIL') ?? config('services.admin.email', 'admin@example.com');
        $password = env('ADMIN_PASSWORD') ?? config('services.admin.password');

        if (blank($password)) {
            $this->command?->warn('ADMIN_PASSWORD is not set — skipping admin seeding. Set it in .env then re-run.');

            return;
        }

        $attributes = [
            'name' => env('ADMIN_NAME') ?? config('services.admin.name', 'Administrator'),
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
            'email_verified_at' => now(),
        ];

        // Prefer the configured email so repeated deploys remain idempotent even
        // when an older database already contains more than one admin record.
        // Only fall back to the first admin when the configured email is new.
        $admin = User::query()->where('email', $email)->first()
            ?? User::query()->where('is_admin', true)->orderBy('id')->first();

        if ($admin) {
            $admin->update($attributes);
        } else {
            User::create($attributes);
        }

        $this->command?->info("Admin user ensured for {$email}.");
    }
}
