<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Models\Role;
use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @group CustomerApi
 * @subgroup Auth
 *
 * APIs for user registration, login, and token management.
 */
class AuthController extends Controller
{
    /**
     * Register a new user
     * 
     * @bodyParam name string required The name of the user. Example: John Doe
     * @bodyParam email string required The email of the user. Example: john@example.com
     * @bodyParam phone_number string required The phone number (98XXXXXXXX). Example: 9841234567
     * @bodyParam password string required The password (min 8 chars). Example: password
     * @bodyParam password_confirmation string required The password confirmation. Example: password
     * @bodyParam fcm_token string (optional) FCM token for notifications.
     */
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($request->password),
            'status' => User::STATUS_PENDING,
        ]);

        if ($request->fcm_token) {
            FcmToken::updateOrCreate(
                ['token' => $request->fcm_token],
                ['user_id' => $user->id]
            );
        }

        $user->sendEmailVerificationNotification();

        $customerRole = Role::where('slug', 'customers')->first();
        if ($customerRole) {
            $user->roles()->attach($customerRole);
        }

        logActivity('registration', 'New user registered via API', [], $user->id);

        return apiResponse(true, __('messages.registration_success'), [], 201);
    }

    /**
     * Login user and create tokens
     * 
     * @bodyParam phone_number string required The phone number. Example: 9841234567
     * @bodyParam password string required The password. Example: password
     * @bodyParam fcm_token string (optional) FCM token for notifications.
     */
    public function login(LoginRequest $request)
    {
        $user = User::where('phone_number', $request->phone_number)->first();

        // 1. Check if user exists and password is correct
        if ($user && Hash::check($request->password, $user->password)) {
            
            // 2. Check if email is verified
            if (!$user->hasVerifiedEmail()) {
                return apiResponse(false, __('messages.verify_email_first'), ['needs_verification' => true], 403);
            }

            // Email verification doubles as the approval step — activate a
            // pending account. Deactivated accounts still require admin action.
            if ($user->status == User::STATUS_PENDING) {
                $user->update(['status' => User::STATUS_ACTIVE]);
            }

            // 3. Check account activation status (1 = Active)
            if ($user->status != User::STATUS_ACTIVE) {
                if ($user->status == User::STATUS_PENDING) {
                    return apiResponse(false, __('messages.account_pending'), ['pending_approval' => true], 403);
                }
                return apiResponse(false, __('messages.account_deactivated'), '', 403);
            }

            return $this->respondWithToken($user, __('messages.login_success'), $request->fcm_token);
        }

        // Authentication failed
        return apiResponse(false, __('messages.invalid_credentials'), '', 401);
    }

    /**
     * Biometric Login via Access Token
     * 
     * @bodyParam access_token string required The access token.
     * @bodyParam fcm_token string (optional) FCM token for notifications.
     */
    public function biometricLogin(Request $request)
    {
        $request->validate([
            'access_token' => 'required|string',
            'fcm_token' => 'nullable|string',
        ]);

        $token = PersonalAccessToken::findToken($request->access_token);

        if (!$token || !$token->can('access')) {
            return apiResponse(false, __('messages.invalid_token'), '', 403);
        }

        $user = $token->tokenable;

        // Email verification doubles as the approval step — a verified account
        // is approved; a pending one only stays pending if it was never verified.
        if ($user->status == User::STATUS_PENDING && $user->hasVerifiedEmail()) {
            $user->update(['status' => User::STATUS_ACTIVE]);
        }

        // Check account activation status (1 = Active)
        if ($user->status != User::STATUS_ACTIVE) {
            if ($user->status == User::STATUS_PENDING) {
                return apiResponse(false, __('messages.account_pending'), ['pending_approval' => true], 403);
            }
            return apiResponse(false, __('messages.account_deactivated'), '', 403);
        }

        logActivity('login', 'User logged in via biometric (access token)', [], $user->id);

        return $this->respondWithToken($user, __('messages.login_success'), $request->fcm_token);
    }

    /**
     * Social Login via Provider Token
     * 
     * Handles login/registration via social providers (google, facebook).
     * 
     * @bodyParam provider string required The provider name (google, facebook).
     * @bodyParam access_token string required The token from provider.
     */
    public function socialLogin(Request $request)
    {
        $request->validate([
            'provider' => 'required|in:google,facebook',
            'access_token' => 'required|string',
            'fcm_token' => 'nullable|string',
        ]);

        $provider = $request->provider;
        $token = $request->access_token;

        try {
            $socialUser = Socialite::driver($provider)->userFromToken($token);
            $user = User::where('email', $socialUser->getEmail())->first();

            if (!$user) {
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'email' => $socialUser->getEmail(),
                    'password' => Hash::make(Str::random(24)),
                    'status' => User::STATUS_ACTIVE, // Social logins are pre-verified
                    'email_verified_at' => now(),
                ]);

                $customerRole = Role::where('slug', 'customers')->first();
                if ($customerRole) {
                    $user->roles()->attach($customerRole);
                }
            }

            if ($user->status == User::STATUS_INACTIVE) {
                return apiResponse(false, __('messages.account_deactivated'), '', 403);
            }

            logActivity('login', 'User logged in via social login (' . $provider . ')', [], $user->id);

            return $this->respondWithToken($user, __('messages.social_login_success'), $request->fcm_token);

        } catch (\Exception $e) {
            return apiResponse(false, __('messages.social_login_failed') . ': ' . $e->getMessage(), '', 400);
        }
    }

    /**
     * Generate common authentication response
     */
    private function respondWithToken(User $user, string $message, ?string $fcmToken = null)
    {
        if ($fcmToken) {
            FcmToken::updateOrCreate(
                ['token' => $fcmToken],
                ['user_id' => $user->id]
            );
        }

        // Revoke old tokens
        $user->tokens()->delete();

        $accessToken = $user->createToken('access_token', ['access'])->plainTextToken;
        $refreshToken = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        $user->load(['roles', 'cards']);

        return apiResponse(true, $message, [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone_number' => $user->phone_number,
                'avatar_url' => $user->avatar_url,
                'status' => $user->status,
                'balance' => $user->balance(),
                'roles' => $user->roles->pluck('name'),
                'cards' => $user->cards->map(function($card) {
                    return [
                        'id' => $card->id,
                        'card_number' => $card->card_number,
                        'hwid' => $card->hwid,
                        'status' => $card->status,
                        'balance' => $card->balance(),
                        'is_active' => (bool)$card->is_currently_active
                    ];
                })
            ]
        ]);
    }

    /**
     * Refresh tokens
     * 
     * @authenticated
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        // Check if the current token has the refresh ability
        if (!$currentToken->can('refresh')) {
            return apiResponse(false, __('messages.invalid_refresh_token'), '', 403);
        }

        // Revoke all current tokens for security on refresh
        $user->tokens()->delete();

        $accessToken = $user->createToken('access_token', ['access'])->plainTextToken;
        $refreshToken = $user->createToken('refresh_token', ['refresh'])->plainTextToken;

        return apiResponse(true, __('messages.tokens_refreshed'), [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Logout user (Revoke tokens)
     * 
     * @authenticated
     */
    public function logout(Request $request)
    {
        if ($request->fcm_token) {
            \App\Models\FcmToken::where('token', $request->fcm_token)->delete();
        }

        logActivity('logout', 'User logged out via API');
        $request->user()->tokens()->delete();

        return apiResponse(true, __('messages.logout_success'));
    }

    /**
     * Forgot Password
     * 
     * Send a password reset link to the user's email.
     * 
     * @bodyParam email string required The user's email. Example: john@example.com
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? apiResponse(true, __($status))
            : apiResponse(false, __($status), '', 400);
    }

    /**
     * Reset Password
     * 
     * Reset the user's password using the token received in email.
     * 
     * @bodyParam token string required The reset token.
     * @bodyParam email string required The user's email.
     * @bodyParam password string required The new password.
     * @bodyParam password_confirmation string required The new password confirmation.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        return $status === Password::PASSWORD_RESET
            ? apiResponse(true, __($status))
            : apiResponse(false, __($status), '', 400);
    }
}
