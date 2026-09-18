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

    public function test_user_with_valid_firebase_token_can_login_successfully(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'is_active' => true,
            'is_admin' => true,
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

    public function test_login_fails_when_user_does_not_exist_in_database(): void
    {
        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('unknown-token')
                ->andReturn([
                    'uid' => 'firebase-uid-456',
                    'email' => 'unregistered@example.com',
                    'email_verified' => true,
                    'name' => 'Unknown User',
                ]);
        });

        $response = $this->from('/login')->post('/login/firebase', [
            'id_token' => 'unknown-token',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email']);
        $this->assertFalse(Auth::check());
    }

    public function test_login_fails_when_user_is_inactive(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);

        $this->mock(FirebaseTokenVerifier::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('verify')
                ->once()
                ->with('token-for-inactive')
                ->andReturn([
                    'uid' => 'firebase-uid-789',
                    'email' => $user->email,
                    'email_verified' => true,
                    'name' => 'Inactive User',
                ]);
        });

        $response = $this->from('/login')->post('/login/firebase', [
            'id_token' => 'token-for-inactive',
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
