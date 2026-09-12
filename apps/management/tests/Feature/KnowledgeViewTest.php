<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KnowledgeViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_supports_card_and_table_views(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        foreach (['' => 'cards', '?view=table&search=example' => 'table', '?view=invalid' => 'cards'] as $query => $expected) {
            $this->get('/admin/knowledge'.$query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Knowledge/Index')->where('filters.view', $expected));
        }
    }
}
