<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\LangController;
use App\Http\Controllers\StopController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\BusController;
use App\Http\Controllers\ParkingController;
use App\Http\Controllers\ParkingImportController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\FareController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\BalanceController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\RouteFinderController;
use App\Http\Controllers\ParkingAttributeController;
use App\Http\Controllers\RideController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\CardApplicationController;
use App\Http\Controllers\CardReaderController;
use App\Http\Controllers\SubscriptionModelController;
use App\Http\Controllers\ServicePartnerController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\LogController;
use App\Models\User;
use App\Models\Bus;
use App\Models\Parking;
use App\Models\Card;
use App\Models\BalanceIn;
use App\Models\BalanceOut;
use Illuminate\Support\Facades\Route;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

/**
 * Public Routes
 */
Route::get('/', function () {
    return redirect()->route('login');
});

// API Documentation Landing Page
Route::get('/docs/api', \App\Http\Controllers\DocsLandingController::class)->name('docs.landing');

// Authentication Routes
Route::controller(LoginController::class)->group(function () {
    Route::get('/login', 'showLoginForm')->name('login');
    Route::post('/login', 'login');
    Route::post('/logout', 'logout')->name('logout');
});

Route::controller(RegisterController::class)->group(function () {
    Route::get('/register', 'showRegistrationForm')->name('register');
    Route::post('/register', 'register');
});

// Email Verification Routes
Route::get('/email/verify', function () {
    return view('auth.verify-email');
})->middleware('auth')->name('verification.notice');

Route::get('/email/verify/{id}/{hash}', function (Request $request, $id, $hash) {
    $user = User::findOrFail($id);

    // 1. Validate Hash
    if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
        return redirect()->route('login')->with('error', 'The verification link is invalid.');
    }

    // 2. Already Verified — still activate if the account is pending
    if ($user->hasVerifiedEmail()) {
        if ($user->status == User::STATUS_PENDING) {
            $user->update(['status' => User::STATUS_ACTIVE]);
        }
        return redirect()->route('login')->with('info', 'Your email is already verified. You can sign in to the app.');
    }

    // 3. Mark as Verified
    if ($user->markEmailAsVerified()) {
        event(new Verified($user));
    }

    // 4. Email verification is the approval step — activate pending accounts.
    //    Deactivated (INACTIVE) accounts are never re-enabled this way.
    if ($user->status == User::STATUS_PENDING) {
        $user->update(['status' => User::STATUS_ACTIVE]);
    }

    return redirect()->route('login')->with('success', 'Thank you! Your email has been verified and your account is now active. You can sign in.');
})->middleware(['signed'])->name('verification.verify');

Route::post('/email/resend', function (Request $request) {
    $request->validate(['phone_number' => 'required|string']);
    $user = User::where('phone_number', $request->phone_number)->first();

    if ($user && !$user->hasVerifiedEmail()) {
        $user->sendEmailVerificationNotification();
        return back()->with('success', 'Verification link sent to your registered email!');
    }

    return back()->with('info', 'If the account exists and is unverified, a new link has been sent.');
})->middleware(['throttle:6,1'])->name('verification.resend');

Route::post('/email/verification-notification', function (Request $request) {
    $request->user()->sendEmailVerificationNotification();
    return back()->with('message', 'Verification link sent!');
})->middleware(['auth', 'throttle:6,1'])->name('verification.send');

// Forgot Password Routes
Route::controller(\App\Http\Controllers\Auth\ForgotPasswordController::class)->group(function () {
    Route::get('/forgot-password', 'showLinkRequestForm')->name('password.request');
    Route::post('/forgot-password', 'sendResetLinkEmail')->name('password.email');
    Route::get('/reset-password/{token}', 'showResetForm')->name('password.reset');
    Route::post('/reset-password', 'reset')->name('password.update');
});

// Utility Routes
Route::get('/lang/{lang}', [LangController::class, 'switch'])->name('lang.switch');
Route::get('/theme/toggle', [ThemeController::class, 'toggle'])->name('theme.toggle');

/**
 * Authenticated Routes
 */
Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', function () {
        $user = auth()->user();

        if ($user->status != User::STATUS_ACTIVE) {
            auth()->logout();
            return redirect()->route('login')->with('warning', 'Your account is not active.');
        }

        $userCount = User::count();
        $cardCount = Card::count();

        $busesQuery = Bus::query();
        $parkingsQuery = Parking::query();
        $servicePartnersQuery = \App\Models\ServicePartner::query();

        if ($user->hasRole('merchant')) {
            $busesQuery->where('merchant_id', $user->id);
            $parkingsQuery->where('merchant_id', $user->id);
            $servicePartnersQuery->where('merchant_id', $user->id);
        } elseif ($user->hasRole('staff')) {
            $busesQuery->whereIn('id', $user->assignedBuses->pluck('id'));
            $parkingsQuery->whereIn('id', $user->assignedParkings->pluck('id'));
            // Assuming staff are linked to merchants, we could filter by merchant_id
            $merchantIds = $user->merchants->pluck('id');
            $servicePartnersQuery->whereIn('merchant_id', $merchantIds);
        }

        $busCount = (clone $busesQuery)->count();
        $parkingCount = (clone $parkingsQuery)->count();
        $servicePartnerCount = (clone $servicePartnersQuery)->count();
        $openSupportCount = \App\Models\SupportRequest::where('status', 'open')->count();

        $buses = $busesQuery->with(['route.stops'])->get(['id', 'name', 'bus_number', 'latitude', 'longitude', 'route_id']);
        $parkings = $parkingsQuery->get(['id', 'name', 'location', 'latitude', 'longitude']);
        $servicePartners = $servicePartnersQuery->get(['id', 'name', 'service_type', 'latitude', 'longitude']);

        return view('dashboard', compact('userCount', 'busCount', 'parkingCount', 'servicePartnerCount', 'cardCount', 'buses', 'parkings', 'servicePartners', 'openSupportCount'));
    })->name('dashboard');

    // Nearby assets for the dashboard map — road (driving) distances via OSRM,
    // role-scoped identically to the dashboard query. Falls back to
    // straight-line distance flagged as such when routing is unavailable.
    Route::get('/dashboard/nearby-assets', function (Request $request) {
        $request->validate([
            'lat'    => 'required|numeric',
            'lng'    => 'required|numeric',
            'radius' => 'nullable|numeric',
        ]);

        $lat = (float) $request->lat;
        $lng = (float) $request->lng;
        $radius = max(0.1, min((float) ($request->radius ?? 5), 50));

        $user = auth()->user();
        $busesQuery = Bus::query()->whereNotNull('latitude')->whereNotNull('longitude');
        $parkingsQuery = Parking::query()->whereNotNull('latitude')->whereNotNull('longitude');

        if ($user->hasRole('merchant')) {
            $busesQuery->where('merchant_id', $user->id);
            $parkingsQuery->where('merchant_id', $user->id);
        } elseif ($user->hasRole('staff')) {
            $busesQuery->whereIn('id', $user->assignedBuses->pluck('id'));
            $parkingsQuery->whereIn('id', $user->assignedParkings->pluck('id'));
        }

        $haversine = function ($itemLat, $itemLng) use ($lat, $lng) {
            $dLat = deg2rad($itemLat - $lat);
            $dLng = deg2rad($itemLng - $lng);
            $a = sin($dLat / 2) ** 2
                + cos(deg2rad($lat)) * cos(deg2rad($itemLat)) * sin($dLng / 2) ** 2;
            return 2 * 6371 * asin(min(1, sqrt($a)));
        };

        $toAsset = fn ($model, $type) => [
            'id'        => $model->id,
            'name'      => $model->name,
            'type'      => $type,
            'latitude'  => (float) $model->latitude,
            'longitude' => (float) $model->longitude,
        ];

        $assets = collect($busesQuery->get(['id', 'name', 'latitude', 'longitude'])->map(fn ($b) => $toAsset($b, 'bus'))->all())
            ->merge($parkingsQuery->get(['id', 'name', 'latitude', 'longitude'])->map(fn ($p) => $toAsset($p, 'parking'))->all())
            ->map(function ($a) use ($haversine, $radius) {
                $a['straight_distance'] = $haversine($a['latitude'], $a['longitude']);
                return $a;
            })
            ->filter(fn ($a) => $a['straight_distance'] <= $radius)
            ->values();

        $legs = app(\App\Services\RoutingService::class)->distancesFrom(
            $lat,
            $lng,
            $assets->map(fn ($a) => ['lat' => $a['latitude'], 'lng' => $a['longitude']])->all()
        );

        $assets = $assets->map(function ($a, $i) use ($legs, $radius) {
            $leg = $legs[$i] ?? null;
            // Keep road-distance semantics consistent with the customer API:
            // a candidate inside the straight-line radius but outside the road
            // radius is excluded once we know the real road distance.
            if ($leg !== null && $leg['distance_km'] > $radius) {
                return null;
            }
            $a['distance_km'] = round($leg['distance_km'] ?? $a['straight_distance'], 2);
            $a['duration_min'] = isset($leg['duration_min']) ? round($leg['duration_min'], 1) : null;
            $a['distance_source'] = $leg !== null ? 'road' : 'straight_line';
            unset($a['straight_distance']);
            return $a;
        })->filter()->values();

        return response()->json(['assets' => $assets]);
    })->name('dashboard.nearby-assets');

    // Profile Management
    Route::controller(ProfileController::class)->group(function () {
        Route::get('/profile', 'show')->name('profile.show');
        Route::put('/profile', 'update')->name('profile.update');
        Route::put('/profile/avatar', 'updateAvatar')->name('profile.update-avatar');
        Route::put('/profile/password', 'password')->name('profile.password');
        Route::post('/profile/kyc', 'submitKyc')->name('profile.kyc');
    });

    // Administration (Super Admin Only)
    Route::middleware(['role:super-admin'])->group(function () {
        // Audit & Reports
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/pending-approval', [AuditController::class, 'pendingApproval'])->name('audit.pending-approval');
        Route::get('/audit/without-cards',    [AuditController::class, 'withoutCards'])->name('audit.without-cards');
        Route::get('/audit/low-balance',      [AuditController::class, 'lowBalance'])->name('audit.low-balance');
        Route::get('/audit/unverified-email', [AuditController::class, 'unverifiedEmail'])->name('audit.unverified-email');
        Route::get('/audit/orphan-cards',     [AuditController::class, 'orphanCards'])->name('audit.orphan-cards');

        // Global Settings
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

        Route::post('/users/bulk-toggle-status', [UserController::class, 'bulkToggleStatus'])->name('users.bulk-toggle-status');
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('users.approve');
        Route::resource('users', UserController::class);
        Route::resource('roles', RoleController::class);
        Route::resource('permissions', PermissionController::class);
        Route::resource('stops', StopController::class);
        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
        Route::post('/activity-logs/sync', [ActivityLogController::class, 'sync'])->name('activity-logs.sync');

        // Laravel Log Viewer
        Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
        Route::post('/logs/clear', [LogController::class, 'clear'])->name('logs.clear');

        Route::resource('parking-attributes', ParkingAttributeController::class);
        Route::resource('subscription-models', SubscriptionModelController::class);
        Route::post('/cards/bulk-toggle-status', [CardController::class, 'bulkToggleStatus'])->name('cards.bulk-toggle-status');
        Route::post('/cards/{card}/toggle-status', [CardController::class, 'toggleStatus'])->name('cards.toggle-status');

        // Banner Management
        Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
        Route::post('/banners/{position}', [BannerController::class, 'update'])->name('banners.update');
        Route::post('/banners/{position}/toggle-status', [BannerController::class, 'toggleStatus'])->name('banners.toggle-status');

        Route::post('/buses-update-locations', [BusController::class, 'updateAllLocations'])->name('buses.update-locations');

        // Support Management
        Route::controller(SupportController::class)->group(function () {
            Route::get('/supports', 'index')->name('supports.index');
            Route::get('/supports/{support}', 'show')->name('supports.show');
            Route::post('/supports/{support}/close', 'close')->name('supports.close');
        });

        // Broadcast Management
        Route::controller(\App\Http\Controllers\BroadcastController::class)->group(function () {
            Route::get('/broadcast', 'index')->name('broadcast.index');
            Route::get('/broadcast/create', 'create')->name('broadcast.create');
            Route::post('/broadcast/store', 'store')->name('broadcast.store');
            Route::get('/broadcast/send', 'sendForm')->name('broadcast.send-form');
            Route::post('/broadcast/send', 'send')->name('broadcast.send');
            Route::get('/broadcast/{broadcast}/show', 'show')->name('broadcast.show');
            Route::delete('/broadcast/{template}', 'destroy')->name('broadcast.destroy');
        });

        // User Impersonation
        Route::get('/impersonate/take/{id}', function($id) {
            auth()->user()->impersonate(User::findOrFail($id));
            return redirect()->route('dashboard');
        })->name('impersonate');

        // Card registry subsections — customers, validators, settlement.
        // Static paths are declared before the cards/{card} resource so they
        // are not shadowed by it.
        Route::prefix('cards')->name('cards.')->group(function () {
            Route::get('/customers', [CardController::class, 'customers'])->name('customers.index');
            Route::get('/customers/{id}', [CardController::class, 'showCustomer'])
                ->whereUuid('id')->name('customers.show');
            Route::get('/validators', [CardController::class, 'validators'])->name('validators.index');
            Route::get('/validators/{id}', [CardController::class, 'showValidator'])
                ->whereUuid('id')->name('validators.qr');
            Route::get('/validators/{id}/payload', [CardController::class, 'validatorQrPayload'])
                ->whereUuid('id')->name('validators.payload');
            Route::get('/settlement', [CardController::class, 'settlement'])->name('settlement.index');
        });
    });

    // Stop Impersonation
    Route::get('/impersonate/leave', function() {
        auth()->user()->leaveImpersonation();
        return redirect()->route('users.index');
    })->name('impersonate.leave');

    // Journey Ledger
    Route::middleware(['role:super-admin,merchant,staff,customers'])->group(function () {
        Route::get('/rides', [RideController::class, 'index'])->name('rides.index');
        Route::get('/tap-ledger', [RideController::class, 'tapLedger'])->name('rides.tap-ledger');
    });

    // Route & Fare Management
    Route::resource('routes', RouteController::class);
    Route::post('/fares/update-matrix-cell', [FareController::class, 'updateMatrixCell'])->name('fares.update-matrix-cell');
    Route::post('/fares/assign-bus', [FareController::class, 'assignBus'])->name('fares.assign-bus');
    Route::get('/fares/matrix-form', [FareController::class, 'getMatrixForm'])->name('fares.matrix-form');
    Route::resource('fares', FareController::class);
    Route::post('/fares/{fare}/approve', [FareController::class, 'approve'])->name('fares.approve');

    // Business Logic Resources
    Route::middleware(['role:merchant'])->group(function () {
        Route::get('/staff/search', [StaffController::class, 'search'])->name('staff.search');
        Route::post('/staff/{staff}/detach', [StaffController::class, 'detach'])->name('staff.detach');
        Route::resource('staff', StaffController::class);
    });

    Route::get('/route-finder', [RouteFinderController::class, 'index'])->name('route-finder.index');
    Route::get('/my-rides', [RideController::class, 'myRides'])->name('rides.my-rides');
    Route::post('/rides/simulate-tap', [RideController::class, 'simulateTap'])->name('rides.simulate-tap');

    // Asset Management (Fleet & Parking) - Restricted to management roles
    Route::middleware(['role:super-admin,merchant,staff'])->group(function () {
        Route::post('/buses/{bus}/toggle-status', [BusController::class, 'toggleStatus'])->name('buses.toggle-status');
        Route::resource('buses', BusController::class);
        Route::middleware(['role:super-admin'])->group(function () {
            Route::get('/parkings/import', [ParkingImportController::class, 'create'])->name('parkings.import');
            Route::post('/parkings/import', [ParkingImportController::class, 'store'])->name('parkings.import.store');
        });
        Route::post('/parkings/{parking}/toggle-status', [ParkingController::class, 'toggleStatus'])->name('parkings.toggle-status');
        Route::resource('parkings', ParkingController::class);
        Route::resource('service-partners', ServicePartnerController::class);
        Route::post('/service-partners/{service_partner}/discounts', [ServicePartnerController::class, 'storeDiscount'])->name('service-partners.discounts.store');
        Route::delete('/service-partners/{service_partner}/discounts/{discount}', [ServicePartnerController::class, 'destroyDiscount'])->name('service-partners.discounts.destroy');
    });

    Route::resource('cards', CardController::class)
        ->where(['card' => '[0-9a-fA-F-]{36}']);
    Route::post('/cards/{card}/request-change', [CardController::class, 'requestChange'])->name('cards.request-change');
    Route::resource('card-applications', CardApplicationController::class);

    // Card Reader — Enrollment & Tap Testing (super-admin only)
    Route::middleware(['role:super-admin'])->prefix('card-reader')->name('card-reader.')->group(function () {
        Route::get('/enroll', [CardReaderController::class, 'enrollPage'])->name('enroll');
        Route::post('/enroll', [CardReaderController::class, 'enrollStore'])->name('enroll-store');
        Route::get('/check-uid', [CardReaderController::class, 'checkUid'])->name('check-uid');
        Route::get('/tap-test', [CardReaderController::class, 'tapTestPage'])->name('tap-test');
        Route::post('/tap-test', [CardReaderController::class, 'processTapTest'])->name('tap-test-process');
    });

    // Support
    Route::post('/support/send', [SupportController::class, 'send'])->name('support.send');

    // Search
    Route::controller(SearchController::class)->group(function () {
        Route::get('/search/global', 'global')->name('search.global');
        Route::get('/search/users', 'users')->name('search.users');
        Route::get('/search/merchants', 'merchants')->name('search.merchants');
        Route::get('/search/references', 'references')->name('search.references');
        Route::get('/search/nearby', 'nearby')->name('search.nearby');
        Route::get('/search/stops', 'stops')->name('search.stops');
        Route::get('/search/find-buses', 'findBuses')->name('search.find-buses');
    });

    // Transaction Management
    Route::controller(BalanceController::class)->group(function () {
        Route::get('/transactions/logs/{userId?}', 'logs')->name('transactions.logs');
        Route::post('/transactions/manual-add', 'manualAdd')->name('transactions.manual-add');
        Route::post('/transactions/manual-deduct', 'manualDeduct')->name('transactions.manual-deduct');
        Route::post('/transactions/khalti-payment', 'khaltiPayment')->name('khalti.payment');
        Route::get('/transactions/khalti-verify', 'khaltiVerify')->name('khalti.verify');
        Route::post('/transactions/stripe-payment', 'stripePayment')->name('stripe.payment');
        Route::get('/transactions/stripe-verify', 'stripeVerify')->name('stripe.verify');

        // Merchant specific
        Route::get('/merchant/income', 'merchantTransactions')->name('merchant.income');
        Route::get('/merchant/withdrawals', 'merchantWithdrawals')->name('merchant.withdrawals');
        Route::post('/merchant/withdraw', 'merchantWithdraw')->name('merchant.withdraw');
    });
});

/**
 * Social Authentication
 */
Route::controller(SocialAuthController::class)->group(function () {
    Route::get('/auth/{provider}', 'redirect')->name('social.redirect');
    Route::get('/auth/{provider}/callback', 'callback')->name('social.callback');
});
