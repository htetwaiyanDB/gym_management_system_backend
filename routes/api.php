<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DashboardReportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlogController;
use App\Http\Controllers\Api\PricingController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\TrainerBookingController;
use App\Http\Controllers\Api\TrainerPackageController;
use App\Http\Controllers\Api\BoxingBookingController;
use App\Http\Controllers\Api\BoxingPackageController;
use App\Http\Controllers\Api\BoxingSessionController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\RfidAttendanceController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\TrainerController;
use App\Http\Controllers\Api\TrainerSessionController;
use App\Http\Controllers\Api\UserMessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserRecordsController;
use App\Http\Controllers\Api\GymClassController;
use App\Http\Controllers\Api\PointController;
use App\Http\Controllers\Api\MembershipPlanController;

// Login endpoint - rate limiting is handled in LoginRequest class
// 5 attempts per email+IP combination with 60 second lockout
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'publicRegister']);
Route::post('/register/verify-email', [AuthController::class, 'verifyEmail']);

// Public blog endpoints
Route::get('/blogs', [BlogController::class, 'index']);
Route::get('/blogs/{slug}', [BlogController::class, 'show']);

// System version endpoint (public - no authentication required)
Route::get('/version', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => config('app.version'),
        'environment' => config('app.env'),
        'laravel_version' => app()->version(),
    ]);
});

// CAPTCHA endpoints (public)
Route::get('/captcha', function () {
    $captcha = app('captcha')->create('default', true);

    return response()->json([
        ...$captcha,
        'captcha' => '<img src="'.$captcha['img'].'" alt="captcha">',
    ]);
});

Route::get('/captcha/refresh', function () {
    $captcha = app('captcha')->create('default', true);

    return response()->json([
        ...$captcha,
        'captcha' => '<img src="'.$captcha['img'].'" alt="captcha">',
    ]);
});

Route::get('/captcha/api/{config?}', function (?string $config = null) {
    return response()->json(
        app('captcha')->create($config ?? 'default', true)
    );
});


// Note: CSRF token endpoint is not needed for token-based API authentication
// For stateful SPA authentication, use Sanctum's built-in endpoint: GET /sanctum/csrf-cookie

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {
    // Get current authenticated user
    Route::get('/user', function (Request $request) {
        $user = $request->user();
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'notifications_enabled' => $user->notifications_enabled,
            ]
        ]);
    });

    // Logout endpoint
    Route::post('/logout', [AuthController::class, 'logout']);

    // Update profile endpoint - Users can update their own profile
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/user/profile', [AuthController::class, 'updateProfile']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/my/messages', [UserMessageController::class, 'messages']);
    Route::post('/my/messages', [UserMessageController::class, 'sendMessage']);

    // Points endpoint (admins see all, user/trainer see their own)
    Route::get('/points', [PointController::class, 'index']);

    // Gym class schedule endpoints (available to authenticated users)
    Route::get('/classes', [GymClassController::class, 'index']);
    Route::get('/classes/{gymClass}', [GymClassController::class, 'show']);

    // Root user can create other users (admission, trainer, user)
    Route::post('/admin/register', [AuthController::class, 'register'])
        ->middleware('administrator');

   Route::middleware('role:trainer')->prefix('trainer')->group(function () {
        Route::get('/home', [TrainerController::class, 'home']);
        Route::get('/check-in', [TrainerController::class, 'checkIn']);
        Route::post('/check-in/scan', [TrainerController::class, 'scanFromQr']);
        Route::get('/subscriptions', [TrainerController::class, 'subscriptions']);
        Route::get('/boxing-bookings', [TrainerController::class, 'boxingBookings']);
        Route::get('/messages', [TrainerController::class, 'messages']);
        Route::post('/messages', [TrainerController::class, 'sendMessage']);
        Route::post('/bookings/{booking}/confirm', [TrainerSessionController::class, 'confirm']);
        Route::post('/boxing-bookings/{booking}/confirm', [BoxingSessionController::class, 'confirm']);
    });

    Route::middleware('role:user')->prefix('user')->group(function () {
        Route::get('/home', [UserController::class, 'home']);
        Route::get('/check-in', [UserController::class, 'checkIn']);
        Route::post('/check-in/scan', [UserController::class, 'scanFromQr']);
        Route::get('/subscriptions', [UserController::class, 'subscriptions']);
        Route::get('/boxing-bookings', [UserController::class, 'boxingBookings']);
        Route::get('/bookings', [UserController::class, 'bookings']);
        Route::get('/messages', [UserController::class, 'messages']);
        Route::post('/messages', [UserController::class, 'sendMessage']);
        Route::post('/bookings/{booking}/confirm', [TrainerSessionController::class, 'confirm']);
        Route::post('/boxing-bookings/{booking}/confirm', [BoxingSessionController::class, 'confirm']);
    });

    // RFID attendance scan endpoint (authenticated system/device token)
    Route::post('/attendance/rfid/scan', [RfidAttendanceController::class, 'scan']);


    // User management endpoints - ONLY accessible by administrator
    Route::middleware('administrator')->group(function () {
        // List users (active by default, add ?deleted=true for deleted users)
        Route::get('/users', [AuthController::class, 'index']);

        // Send password reset link to a user
        Route::post('/users/forgot-password', [AuthController::class, 'sendPasswordResetLink']);

        // Delete a user (permanent delete)
        Route::delete('/users/{id}', [AuthController::class, 'destroy']);


        // User records endpoint
        Route::get('/users/{id}/records', [UserRecordsController::class, 'records']);

        // Update a user
        Route::put('/users/{id}', [AuthController::class, 'updateUser']);
        Route::patch('/users/{id}', [AuthController::class, 'updateUser']);

        // Restore a soft-deleted user
        Route::post('/users/{id}/restore', [AuthController::class, 'restore']);

        // Permanently delete a user (force delete)
        Route::delete('/users/{id}/force', [AuthController::class, 'forceDestroy']);

        // Blog management endpoints
        Route::post('/blogs', [BlogController::class, 'store']);
        Route::put('/blogs/{blog}', [BlogController::class, 'update']);
        Route::patch('/blogs/{blog}', [BlogController::class, 'update']);
        Route::delete('/blogs/{blog}', [BlogController::class, 'destroy']);

        // Trainer pricing endpoints
        Route::get('/pricing', [PricingController::class, 'index']);
        Route::put('/pricing/one-month', [PricingController::class, 'updateOneMonth']);
        Route::put('/pricing/three-months', [PricingController::class, 'updateThreeMonths']);
        Route::put('/pricing/six-months', [PricingController::class, 'updateSixMonths']);
        Route::put('/pricing/twelve-months', [PricingController::class, 'updateTwelveMonths']);
        Route::put('/pricing/class', [PricingController::class, 'updateClass']);
        Route::put('/pricing/class-plans/three-months', [PricingController::class, 'updateClassThreeMonths']);
        Route::put('/pricing/class-plans/six-months', [PricingController::class, 'updateClassSixMonths']);
        Route::put('/pricing/class-plans/twelve-months', [PricingController::class, 'updateClassTwelveMonths']);
        Route::put('/pricing/trainers/{user}', [PricingController::class, 'updateTrainer']);

        // Membership and class pricing plan management endpoints
        Route::get('/membership-plans', [MembershipPlanController::class, 'index']);
        Route::get('/membership-plans/{membershipPlan}', [MembershipPlanController::class, 'show']);
        Route::post('/membership-plans', [MembershipPlanController::class, 'store']);
        Route::put('/membership-plans/{membershipPlan}', [MembershipPlanController::class, 'update']);
        Route::patch('/membership-plans/{membershipPlan}', [MembershipPlanController::class, 'update']);
        Route::delete('/membership-plans/{membershipPlan}', [MembershipPlanController::class, 'destroy']);

        // Trainer booking endpoints
        Route::get('/trainer-bookings', [TrainerBookingController::class, 'index']);
        Route::get('/trainer-bookings/options', [TrainerBookingController::class, 'options']);
        Route::post('/trainer-bookings', [TrainerBookingController::class, 'store']);
        Route::patch('/trainer-bookings/{booking}/mark-paid', [TrainerBookingController::class, 'markPaid']);
        Route::patch('/trainer-bookings/session/{booking}/sessions', [TrainerBookingController::class, 'updateSessions']);
        Route::patch('/trainer-bookings/{booking}/hold', [TrainerBookingController::class, 'hold']);
        Route::patch('/trainer-bookings/{booking}/resume', [TrainerBookingController::class, 'resume']);
        Route::patch('/trainer-bookings/{booking}/extend', [TrainerBookingController::class, 'extend']);
        Route::delete('/trainer-bookings/{booking}', [TrainerBookingController::class, 'destroy']);

        //Boxing bookin endpoints
        Route::get('/boxing-bookings', [BoxingBookingController::class, 'index']);
        Route::get('/boxing-bookings/options', [BoxingBookingController::class, 'options']);
        Route::post('/boxing-bookings', [BoxingBookingController::class, 'store']);
        Route::patch('/boxing-bookings/{booking}/mark-paid', [BoxingBookingController::class, 'markPaid']);
        Route::patch('/boxing-bookings/session/{booking}/sessions', [BoxingBookingController::class, 'updateSessions']);
        Route::patch('/boxing-bookings/{booking}/hold', [BoxingBookingController::class, 'hold']);
        Route::patch('/boxing-bookings/{booking}/resume', [BoxingBookingController::class, 'resume']);
        Route::patch('/boxing-bookings/{booking}/extend', [BoxingBookingController::class, 'extend']);
        Route::delete('/boxing-bookings/{booking}', [BoxingBookingController::class, 'destroy']);


         // Trainer package endpoints
        Route::get('/trainer-packages', [TrainerPackageController::class, 'index']);
        Route::get('/trainer-packages/{trainerPackage}', [TrainerPackageController::class, 'show']);
        Route::post('/trainer-packages', [TrainerPackageController::class, 'store']);
        Route::put('/trainer-packages/{trainerPackage}', [TrainerPackageController::class, 'update']);
        Route::patch('/trainer-packages/{trainerPackage}', [TrainerPackageController::class, 'update']);
        Route::delete('/trainer-packages/{trainerPackage}', [TrainerPackageController::class, 'destroy']);
        Route::patch('/trainer-bookings/{booking}/mark-active', [TrainerBookingController::class, 'markActive']);
        Route::patch('/trainer-bookings/{booking}/mark-hold', [TrainerBookingController::class, 'markHold']);

        //Boxing package endpoints
        Route::get('/boxing-packages', [BoxingPackageController::class, 'index']);
        Route::get('/boxing-packages/{boxingPackage}', [BoxingPackageController::class, 'show']);
        Route::post('/boxing-packages', [BoxingPackageController::class, 'store']);
        Route::put('/boxing-packages/{boxingPackage}', [BoxingPackageController::class, 'update']);
        Route::patch('/boxing-packages/{boxingPackage}', [BoxingPackageController::class, 'update']);
        Route::delete('/boxing-packages/{boxingPackage}', [BoxingPackageController::class, 'destroy']);
        Route::patch('/boxing-bookings/{booking}/mark-active', [BoxingBookingController::class, 'markActive']);
        Route::patch('/boxing-bookings/{booking}/mark-hold', [BoxingBookingController::class, 'markHold']);

        // Subscription management endpoints
        Route::prefix('subscriptions')->group(function () {
        Route::get('/', [SubscriptionController::class, 'index']);
        Route::post('/', [SubscriptionController::class, 'store']);
        Route::post('/all-hold', [SubscriptionController::class, 'holdAll']);
        Route::post('/all-resume', [SubscriptionController::class, 'resumeAll']);
        Route::get('/options', [SubscriptionController::class, 'options']);
        Route::post('/{subscription}/hold', [SubscriptionController::class, 'hold']);
        Route::post('/{subscription}/resume', [SubscriptionController::class, 'resume']);
        Route::patch('/{subscription}/extend', [SubscriptionController::class, 'extend']);
        Route::delete('/{subscription}', [SubscriptionController::class, 'destroy']);
        });

        Route::post('/subscription/all-hold', [SubscriptionController::class, 'holdAll']);
        Route::post('/subscription/all-resume', [SubscriptionController::class, 'resumeAll']);

        Route::prefix('attendance')->group(function () {
            Route::get('/users', [AttendanceController::class, 'users']);
            Route::get('/qr', [AttendanceController::class, 'qr']);
            Route::get('/records', [AttendanceController::class, 'records']);
            Route::get('/checked-in', [AttendanceController::class, 'checkedIn']);
            Route::post('/scan', [AttendanceController::class, 'scan']);
            Route::post('/scan/qr', [AttendanceController::class, 'scanFromQr']);
            Route::post('/qr/refresh', [AttendanceController::class, 'refreshQr']);
            Route::post('/rfid/register', [RfidAttendanceController::class, 'registerCard']);
        });

        Route::get('/points/{point}', [PointController::class, 'show']);
        Route::post('/points', [PointController::class, 'store']);
        Route::post('/points/adjust', [PointController::class, 'adjust']);
        Route::put('/points/{point}', [PointController::class, 'update']);
        Route::patch('/points/{point}', [PointController::class, 'update']);
        Route::delete('/points/{point}', [PointController::class, 'destroy']);

        Route::get('/dashboard/attendance-report', [DashboardController::class, 'attendanceReport']);
        Route::get('/dashboard/growth-summary', [DashboardController::class, 'growthSummary']);
        Route::get('/dashboard/export/{format}', [DashboardReportController::class, 'export'])
            ->whereIn('format', ['excel', 'json']);

        Route::get('/messages', [MessageController::class, 'conversations']);
        Route::get('/messages/{user}', [MessageController::class, 'thread']);
        Route::post('/messages/{user}', [MessageController::class, 'store']);

        // Gym class schedule management endpoints
        Route::post('/classes', [GymClassController::class, 'store']);
        Route::put('/classes/{gymClass}', [GymClassController::class, 'update']);
        Route::patch('/classes/{gymClass}', [GymClassController::class, 'update']);
        Route::delete('/classes/{gymClass}', [GymClassController::class, 'destroy']);
    });
});
