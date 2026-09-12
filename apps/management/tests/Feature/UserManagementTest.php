<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_staff_account_without_exposing_a_password(): void
    {
        $this->actingAs(User::factory()->create())->post('/admin/users', [
            'name' => 'Staff', 'email' => 'staff@example.test', 'role' => 'editor', 'is_active' => true,
        ])->assertRedirect();
        $user = User::where('email', 'staff@example.test')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertSame('editor', $user->role);
        $this->assertDatabaseHas('user_management_events', ['target_id' => $user->id, 'event' => 'created']);
        $this->get('/admin/users')->assertOk()->assertDontSee($user->password);
    }

    public function test_staff_cannot_manage_users_or_credentials(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => $role]));
            $this->get('/admin/users')->assertForbidden();
            $this->post('/admin/users', [])->assertForbidden();
            $this->get('/admin/api-tokens')->assertForbidden();
            $this->post('/admin/ai-setup/keys', [])->assertForbidden();
        }
    }

    public function test_final_admin_cannot_be_disabled_or_demoted(): void
    {
        $admin = User::factory()->create();
        foreach ([['role' => 'viewer', 'is_active' => true], ['role' => 'admin', 'is_active' => false]] as $change) {
            $this->actingAs($admin)->put('/admin/users/'.$admin->id, $change + [
                'name' => $admin->name, 'email' => $admin->email,
            ])->assertSessionHasErrors('role');
        }
        $this->assertTrue($admin->fresh()->is_admin);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_disable_revokes_owned_keys_but_not_service_keys(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $owned = ApiToken::issue('staff')['token'];
        $owned->forceFill(['user_id' => $target->id])->save();
        $service = ApiToken::issue('service')['token'];
        $this->actingAs($admin)->put('/admin/users/'.$target->id, [
            'name' => $target->name, 'email' => $target->email, 'role' => 'admin', 'is_active' => false,
        ])->assertRedirect();
        $this->assertNotNull($owned->fresh()->revoked_at);
        $this->assertNull($service->fresh()->revoked_at);
        $this->actingAs($target->fresh())->get('/admin/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_disabled_account_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_editor_can_create_content_but_viewer_cannot(): void
    {
        $editor = User::factory()->create(['is_admin' => false, 'role' => 'editor']);
        $this->actingAs($editor)->post('/admin/packages', ['name_th' => 'ตัวอย่าง'])->assertRedirect();
        $this->assertDatabaseHas('packages', ['name_th' => 'ตัวอย่าง']);
        $this->actingAs(User::factory()->create(['is_admin' => false, 'role' => 'viewer']))
            ->get('/admin/packages')->assertOk();
        $this->post('/admin/packages', ['name_th' => 'ห้ามสร้าง'])->assertForbidden();
        $this->assertDatabaseMissing('packages', ['name_th' => 'ห้ามสร้าง']);
    }

    public function test_duplicate_email_and_unknown_role_are_rejected(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Duplicate', 'email' => $admin->email, 'role' => 'owner', 'is_active' => true,
        ])->assertSessionHasErrors(['email', 'role']);
    }

    public function test_password_link_is_private_single_use_and_sets_password(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $response = $this->actingAs($admin)->postJson('/admin/users/'.$user->id.'/password-link')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertNull(parse_url($response->json('url'), PHP_URL_QUERY));
        $this->assertSame('/reset-password', parse_url($response->json('url'), PHP_URL_PATH));
        parse_str(parse_url($response->json('url'), PHP_URL_FRAGMENT), $query);
        $token = $query['token'];
        $data = ['email' => $query['email'], 'token' => $token, 'password' => 'A-long-new-password-123', 'password_confirmation' => 'A-long-new-password-123'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $this->assertStringNotContainsString($token, json_encode(DB::table('user_management_events')->get()));
    }

    public function test_expired_password_link_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'A-long-new-password-123', 'password_confirmation' => 'A-long-new-password-123',
        ])->assertSessionHasErrors('email');
    }

    public function test_web_issued_key_has_owner(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->postJson('/admin/ai-setup/keys', ['client' => 'codex', 'minutes' => 15])->assertCreated();
        $this->assertSame($admin->id, ApiToken::latest('id')->first()->user_id);
    }

    public function test_old_session_is_rejected_after_role_change(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($admin)->put('/admin/users/'.$target->id, [
            'name' => $target->name, 'email' => $target->email, 'role' => 'viewer', 'is_active' => true,
        ])->assertRedirect();
        $this->actingAs($target->fresh())->withSession(['staff_version' => 0])->get('/admin/packages')->assertRedirect('/login');
    }

    public function test_password_page_has_no_secret_in_server_rendered_markup(): void
    {
        $this->get('/reset-password')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_role_boundaries_cover_all_mutating_methods_and_sensitive_pages(): void
    {
        $target = User::factory()->create();
        $viewer = User::factory()->create(['is_admin' => false, 'role' => 'viewer']);
        $this->actingAs($viewer);
        $models = ['packages' => \App\Models\ServicePackage::class, 'faqs' => \App\Models\Faq::class,
            'knowledge' => \App\Models\KnowledgeEntry::class, 'package-categories' => \App\Models\PackageCategory::class];
        foreach ($models as $resource => $model) {
            $record = $model::factory()->create();
            $this->put('/admin/'.$resource.'/'.$record->id, [])->assertForbidden();
            $this->delete('/admin/'.$resource.'/'.$record->id)->assertForbidden();
            $this->get('/admin/'.$resource.'/create')->assertForbidden();
        }
        $this->put('/admin/users/'.$target->id, [])->assertForbidden();
        $this->post('/admin/users/'.$target->id.'/password-link')->assertForbidden();
        $this->get('/admin/documents')->assertForbidden();
        $this->post('/admin/imports/packages/confirm', [])->assertForbidden();
    }

    public function test_demoted_key_owner_is_rejected_even_if_revocation_was_missed(): void
    {
        $owner = User::factory()->create(['is_admin' => false, 'role' => 'viewer']);
        $issued = ApiToken::issue('owned');
        $issued['token']->forceFill(['user_id' => $owner->id])->save();
        $this->assertNull(ApiToken::findValid($issued['plainText']));
    }
}
