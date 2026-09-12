<?php

namespace Tests\Feature;

use App\Models\ServicePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Tests\TestCase;

class EditorPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_receives_map_and_can_render_synthetic_review_pages(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'name' => 'UI Review', 'email' => 'review@example.invalid']));
        $item = ServicePackage::create(['name_th' => 'คอนโดตัวอย่าง บางนา 1 ห้องนอน',
            'item_type' => 'property', 'price' => 2600000, 'location_text' => 'บางนา',
            'bedrooms' => 1, 'map_url' => 'https://www.google.com/maps?q=Bangkok']);
        $response = $this->get('/admin/packages/'.$item->id.'/edit')->assertOk();
        $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('Packages/Form')->where('pkg.map_url', $item->map_url));

        // Opt-in synthetic HTML artifacts for visual QA, never a runtime route.
        $output = getenv('UI_REVIEW_OUTPUT');
        if (! $output) return;
        if (! is_dir($output)) mkdir($output, 0700, true);
        file_put_contents($output.'/editor.html', $response->getContent());
        $props = ['changeSets' => ['data' => [[
            'id' => '01a08ff1-f907-73a3-a087-f9b9a7b3b5a8', 'status' => 'applied',
            'operation_count' => 1, 'created_at' => '2026-09-11T10:09:54Z',
            'reviewed_at' => null, 'operations' => [['entity' => 'faq', 'action' => 'create']],
        ]]], 'filter' => ''];
        $page = Inertia::render('AgentChanges/Index', $props)->toResponse(request());
        file_put_contents($output.'/proposals.html', $page->getContent());
    }
}
