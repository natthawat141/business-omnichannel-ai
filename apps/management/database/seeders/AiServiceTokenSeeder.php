<?php

namespace Database\Seeders;

use App\Models\ApiToken;
use Illuminate\Database\Seeder;

class AiServiceTokenSeeder extends Seeder
{
    public function run(): void
    {
        $plainText = env('AI_SERVICE_TOKEN') ?? config('services.ai.token');
        if (blank($plainText)) {
            $this->command?->warn('AI_SERVICE_TOKEN is not set — the AI service cannot read Management.');
            return;
        }

        ApiToken::updateOrCreate(
            ['name' => 'ai-orchestrator'],
            [
                'token_hash' => hash('sha256', $plainText),
                'prefix' => substr($plainText, 0, 11),
                'abilities' => ['read'],
                'is_protected' => true,
                'revoked_at' => null,
                'expires_at' => null,
            ],
        );
    }
}
