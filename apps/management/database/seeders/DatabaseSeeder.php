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

        if (filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL)) {
            $this->call(RealEstateDemoSeeder::class);
        }
    }

    /**
     * Create/refresh a single admin user from environment variables only.
     * Never hardcodes a real password. Skips gracefully if ADMIN_PASSWORD is unset.
     */
    private function seedAdmin(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            $this->command?->warn('ADMIN_PASSWORD is not set — skipping admin seeding. Set it in .env then re-run.');

            return;
        }

        $attributes = [
            'name' => env('ADMIN_NAME', 'Administrator'),
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
