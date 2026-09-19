<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\FirebaseTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class FirebaseLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_user_with_valid_firebase_token_can_login_successfully(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'is_active' => true,
            'is_admin' => true,
            'approval_status' => 'approved',
        ]);

        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('valid-token')
                ->andReturn([
                    'uid' => 'firebase-uid-123',
                    'email' => $user->email,
                    'email_verified' => true,
                    'name' => 'Staff Member',
                ]);
        });

        $response = $this->post('/login/firebase', [
            'id_token' => 'valid-token',
            'remember' => true,
        ]);

        $response->assertRedirect('/admin/dashboard');
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
        $this->assertSame($user->session_version, session('staff_version'));
    }

    public function test_new_google_user_is_auto_registered_with_pending_approval(): void
    {
        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('new-user-token')
                ->andReturn([
                    'uid' => 'firebase-uid-456',
                    'email' => 'newcomer@example.com',
                    'email_verified' => true,
                    'name' => 'New Customer',
                ]);
        });

        $response = $this->post('/login/firebase', [
            'id_token' => 'new-user-token',
        ]);

        $response->assertRedirect('/pending-approval');
        $this->assertTrue(Auth::check());

        $created = User::where('email', 'newcomer@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('New Customer', $created->name);
        $this->assertSame('google', $created->auth_provider);
        $this->assertSame('pending', $created->approval_status);
        $this->assertFalse($created->is_active);
    }

    public function test_pending_user_is_redirected_to_pending_approval_page(): void
    {
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'is_active' => false,
            'approval_status' => 'pending',
            'auth_provider' => 'google',
        ]);

        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('pending-user-token')
                ->andReturn([
                    'uid' => 'firebase-uid-789',
                    'email' => $user->email,
                    'email_verified' => true,
                    'name' => 'Pending User',
                ]);
        });

        $response = $this->post('/login/firebase', [
            'id_token' => 'pending-user-token',
        ]);

        $response->assertRedirect('/pending-approval');
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_pending_user_cannot_access_admin_routes(): void
    {
        $user = User::factory()->create([
            'email' => 'pending2@example.com',
            'is_active' => false,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($user);
        session(['staff_version' => $user->session_version]);

        $response = $this->get('/admin/dashboard');
        $response->assertRedirect('/pending-approval');
    }

    public function test_admin_can_approve_pending_user(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $pending = User::factory()->create([
            'email' => 'pending3@example.com',
            'is_active' => false,
            'approval_status' => 'pending',
            'role' => 'viewer',
        ]);

        $this->actingAs($admin);
        session(['staff_version' => $admin->session_version]);

        $response = $this->post("/admin/users/{$pending->id}/approve", [
            'role' => 'editor',
        ]);

        $response->assertRedirect();
        $this->assertSame('approved', $pending->fresh()->approval_status);
        $this->assertTrue($pending->fresh()->is_active);
        $this->assertSame('editor', $pending->fresh()->role);
    }

    public function test_admin_can_reject_pending_user(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'approval_status' => 'approved',
        ]);

        $pending = User::factory()->create([
            'email' => 'pending4@example.com',
            'is_active' => false,
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin);
        session(['staff_version' => $admin->session_version]);

        $response = $this->post("/admin/users/{$pending->id}/reject");

        $response->assertRedirect();
        $this->assertSame('rejected', $pending->fresh()->approval_status);
        $this->assertFalse($pending->fresh()->is_active);
    }

    public function test_rejected_user_cannot_login_with_firebase(): void
    {
        $user = User::factory()->create([
            'email' => 'rejected@example.com',
            'is_active' => false,
            'approval_status' => 'rejected',
        ]);

        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('token-for-rejected')
                ->andReturn([
                    'uid' => 'firebase-uid-rejected',
                    'email' => $user->email,
                    'email_verified' => true,
                    'name' => 'Rejected User',
                ]);
        });

        $response = $this->from('/login')->post('/login/firebase', [
            'id_token' => 'token-for-rejected',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    public function test_login_fails_when_token_verification_fails(): void
    {
        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('invalid-token')
                ->andThrow(ValidationException::withMessages([
                    'id_token' => 'โทเค็นไม่ถูกต้อง',
                ]));
        });

        $response = $this->from('/login')->post('/login/firebase', [
            'id_token' => 'invalid-token',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['id_token']);
        $this->assertFalse(Auth::check());
    }

    public function test_login_fails_when_email_is_missing(): void
    {
        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('token-without-email')
                ->andReturn([
                    'uid' => 'firebase-uid-000',
                    'email' => null,
                    'email_verified' => false,
                    'name' => 'No Email User',
                ]);
        });

        $response = $this->from('/login')->post('/login/firebase', [
            'id_token' => 'token-without-email',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
        $this->assertFalse(Auth::check());
    }
}
