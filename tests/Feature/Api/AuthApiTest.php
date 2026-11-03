<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the customers role as it's required during registration
        Role::create([
            'name' => 'Customers',
            'slug' => 'customers',
        ]);
    }

    /**
     * Test user registration successfully.
     */
    public function test_user_can_register()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'phone_number' => '9812345678',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => true,
                'message' => 'User registered successfully. Please verify your email.',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'phone_number' => '9812345678',
            'status' => User::STATUS_PENDING,
        ]);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertCount(0, $user->cards);
    }

    /**
     * Test registration validation errors.
     */
    public function test_registration_validation_errors()
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(422)
            ->assertJson([
                'status' => false,
            ])
            ->assertJsonStructure([
                'content' => ['name', 'email', 'phone_number', 'password']
            ]);
    }

    /**
     * Test user login successfully.
     */
    public function test_user_can_login()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Login successful',
            ])
            ->assertJsonStructure([
                'content' => [
                    'access_token',
                    'refresh_token',
                    'user' => [
                        'id', 'name', 'email', 'phone_number', 'balance'
                    ]
                ]
            ]);
    }

    /**
     * Test user login successfully and FCM token storage.
     */
    public function test_user_can_login_with_fcm_token()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'password',
            'fcm_token' => 'sample-fcm-token',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('fcm_tokens', [
            'user_id' => $user->id,
            'token' => 'sample-fcm-token',
        ]);
    }

    /**
     * Test login failure with invalid credentials.
     */
    public function test_login_failure_invalid_credentials()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid login credentials',
            ]);
    }

    /**
     * Test login failure when email is not verified.
     */
    public function test_login_failure_unverified_email()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'password',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Please verify your email address before logging in.',
            ]);
    }

    /**
     * Test login auto-activates a pending account once the email is verified.
     * Email verification is the approval step — no admin action needed.
     */
    public function test_login_auto_activates_pending_verified_account()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_PENDING,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => true]);

        $this->assertEquals(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    /**
     * Test email verification activates a pending user.
     */
    public function test_email_verification_activates_pending_user()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_PENDING,
            'email_verified_at' => null,
        ]);

        $url = \Illuminate\Support\Facades\URL::signedRoute('verification.verify', [
            'id'   => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $response = $this->get($url);

        $response->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertEquals(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    /**
     * Test verification does not reactivate a deactivated account.
     */
    public function test_email_verification_does_not_reactivate_deactivated_user()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_INACTIVE,
            'email_verified_at' => null,
        ]);

        $url = \Illuminate\Support\Facades\URL::signedRoute('verification.verify', [
            'id'   => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->get($url);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertEquals(User::STATUS_INACTIVE, $user->fresh()->status);
    }

    /**
     * Test login failure for a deactivated account.
     */
    public function test_login_failure_deactivated_account()
    {
        $user = User::factory()->create([
            'phone_number' => '9812345678',
            'password' => Hash::make('password'),
            'status' => User::STATUS_INACTIVE,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone_number' => '9812345678',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
    }

    /**
     * Test logout successfully.
     */
    public function test_user_can_logout()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Logged out successfully',
            ]);

        $this->assertCount(0, $user->tokens);
    }

    /**
     * Test logout successfully and FCM token removal.
     */
    public function test_user_can_logout_and_clear_fcm_token()
    {
        $user = User::factory()->create();
        \App\Models\FcmToken::create([
            'user_id' => $user->id,
            'token' => 'sample-fcm-token',
        ]);
        
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout', [
            'fcm_token' => 'sample-fcm-token',
        ]);

        $response->assertStatus(200);

        $this->assertSoftDeleted('fcm_tokens', [
            'token' => 'sample-fcm-token',
        ]);
    }

    /**
     * Test biometric login successfully.
     */
    public function test_user_can_login_via_biometric()
    {
        $user = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $accessToken = $user->createToken('access_token', ['access'])->plainTextToken;

        $response = $this->postJson('/api/auth/biometric-login', [
            'access_token' => $accessToken,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Login successful',
            ])
            ->assertJsonStructure([
                'content' => [
                    'access_token',
                    'refresh_token',
                    'user' => [
                        'id', 'name', 'email'
                    ]
                ]
            ]);
    }

    /**
     * Test biometric login failure with invalid token.
     */
    public function test_biometric_login_failure_invalid_token()
    {
        $response = $this->postJson('/api/auth/biometric-login', [
            'access_token' => 'invalid-token',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid token provided',
            ]);
    }

    /**
     * Test biometric login failure with a refresh token.
     */
    public function test_biometric_login_failure_with_refresh_token()
    {
        $user = User::factory()->create();
        $refreshToken = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        $response = $this->postJson('/api/auth/biometric-login', [
            'access_token' => $refreshToken,
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid token provided',
            ]);
    }

    /**
     * Test token refresh.
     */
    public function test_user_can_refresh_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['refresh']);

        $response = $this->postJson('/api/auth/refresh');

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => 'Tokens refreshed successfully',
            ])
            ->assertJsonStructure([
                'content' => [
                    'access_token',
                    'refresh_token',
                ]
            ]);
    }

    /**
     * Test refresh failure with access token instead of refresh token.
     */
    public function test_refresh_fails_with_access_token()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['access']);

        $response = $this->postJson('/api/auth/refresh');

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'Invalid token type for refresh',
            ]);
    }
}
