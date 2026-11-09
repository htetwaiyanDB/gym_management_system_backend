<?php


use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Admin\TrainerBookingController;
use App\Http\Controllers\Admin\DashboardReportController;
use Illuminate\Support\Facades\Route;
use App\Models\MemberMembership;
use App\Models\TrainerBooking;
use App\Models\User;
use App\Models\RfidAttendance;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| Web Routes (Blade Admin + SPA host)
|--------------------------------------------------------------------------
|
| Web (Blade) Auth uses sessions/cookies:
|   - GET  /login
|   - POST /login
|   - GET  /register
|   - POST /register
|   - POST /logout
| and redirects to /dashboard (RouteServiceProvider::HOME)
|
| API Auth uses tokens and stays in routes/api.php:
|   - POST /api/login
|   - POST /api/register
|   - POST /api/logout
|
| SPA is served under /app/* so it doesn't conflict with /login, /register, /dashboard
|
*/

// Home page (optional)
Route::get('/', function () {
    return view('welcome');
})->name('home');

// Blade dashboard (admin dashboard)
Route::get('/dashboard', function () {
        $months = collect(range(0, 5))->map(function ($offset) {
        return Carbon::now()->subMonths(5 - $offset)->startOfMonth();
    });

    $chartLabels = $months->map(fn ($month) => $month->format('M Y'));

    $userCounts = $months->map(fn ($month) => User::whereBetween('created_at', [
        $month,
        $month->copy()->endOfMonth(),
    ])->count());

    $subscriptionCounts = $months->map(fn ($month) => MemberMembership::whereBetween('start_date', [
        $month->copy()->startOfMonth(),
        $month->copy()->endOfMonth(),
    ])->count());

    $trainerBookingCounts = $months->map(fn ($month) => TrainerBooking::whereBetween('session_datetime', [
        $month,
        $month->copy()->endOfMonth(),
    ])->count());

        $reportDays = collect(range(0, 6))->map(function ($offset) {
        return Carbon::today()->subDays(6 - $offset);
    });

    $reportLabels = $reportDays->map(fn ($day) => $day->format('M d'));

    $checkInCounts = $reportDays->map(fn ($day) => RfidAttendance::query()
        ->whereDate('attendance_date', $day)
        ->whereNotNull('check_in_time')
        ->count());

    $checkOutCounts = $reportDays->map(fn ($day) => RfidAttendance::query()
        ->whereDate('attendance_date', $day)
        ->whereNotNull('check_out_time')
        ->count());


    return view('dashboard', [
        'totalUsers' => User::count(),
        'totalSubscriptions' => MemberMembership::count(),
        'totalTrainerBookings' => TrainerBooking::count(),
        'chartLabels' => $chartLabels,
        'userCounts' => $userCounts,
        'subscriptionCounts' => $subscriptionCounts,
        'trainerBookingCounts' => $trainerBookingCounts,
        'reportLabels' => $reportLabels,
        'checkInCounts' => $checkInCounts,
        'checkOutCounts' => $checkOutCounts,
        'latestUsers' => User::latest()->take(5)->get(),
        'latestSubscriptions' => MemberMembership::with(['member', 'plan'])
            ->latest('start_date')
            ->take(5)
            ->get(),
        'latestTrainerBookings' => TrainerBooking::with(['member', 'trainer'])
            ->latest('session_datetime')
            ->take(5)
            ->get(),
    ]);
})->middleware(['auth'])->name('dashboard');

Route::get('/dashboard/export/{format}', [DashboardReportController::class, 'export'])
    ->middleware(['auth', 'administrator'])
    ->whereIn('format', ['excel', 'json'])
    ->name('dashboard.export');

Route::get('/attendance', [AttendanceController::class, 'index'])
    ->middleware(['auth', 'administrator'])
    ->name('attendance.index');
Route::get('/attendance/records', [AttendanceController::class, 'records'])
    ->middleware(['auth', 'administrator'])
    ->name('attendance.records');
Route::get('/attendance/checked-in', [AttendanceController::class, 'checkedIn'])
    ->middleware(['auth', 'administrator'])
    ->name('attendance.checked-in');
Route::get('/attendance/scan', [AttendanceController::class, 'scanFromQr'])
    ->middleware('auth')
    ->name('attendance.scan-qr');
Route::post('/attendance/scan', [AttendanceController::class, 'scan'])
    ->middleware(['auth', 'administrator'])
    ->name('attendance.scan');
Route::post('/attendance/qr/refresh', [AttendanceController::class, 'refreshQr'])
    ->middleware(['auth', 'administrator'])
    ->name('attendance.qr.refresh');

Route::view('/reports', 'pages.reports')->name('reports.index');

Route::view('/users', 'pages.users')->middleware(['auth', 'administrator'])->name('users.index');

Route::view('/subscriptions', 'pages.subscriptions')
    ->middleware(['auth', 'administrator'])
    ->name('subscriptions.index');

Route::get('/pricing', [PricingController::class, 'index'])
    ->middleware(['auth', 'administrator'])
    ->name('pricing.index');
Route::put('/pricing/monthly', [PricingController::class, 'updateMonthly'])
    ->middleware(['auth', 'administrator'])
    ->name('pricing.update-monthly');
Route::put('/pricing/quarterly', [PricingController::class, 'updateQuarterly'])
    ->middleware(['auth', 'administrator'])
    ->name('pricing.update-quarterly');
Route::put('/pricing/annual', [PricingController::class, 'updateAnnual'])
    ->middleware(['auth', 'administrator'])
    ->name('pricing.update-annual');
Route::put('/pricing/trainers/{user}', [PricingController::class, 'updateTrainer'])
    ->middleware(['auth', 'administrator'])
    ->name('pricing.update-trainer');

Route::get('/trainer-bookings', [TrainerBookingController::class, 'index'])
    ->middleware(['auth', 'administrator'])
    ->name('trainer-bookings.index');
Route::post('/trainer-bookings', [TrainerBookingController::class, 'store'])
    ->middleware(['auth', 'administrator'])
    ->name('trainer-bookings.store');
Route::patch('/trainer-bookings/{booking}/mark-paid', [TrainerBookingController::class, 'markPaid'])
    ->middleware(['auth', 'administrator'])
    ->name('trainer-bookings.mark-paid');

Route::view('/messages', 'pages.messages')
    ->middleware(['auth', 'administrator'])
    ->name('messages.index');

Route::get('/blogs', [BlogController::class, 'index'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.index');
Route::get('/blogs/create', [BlogController::class, 'create'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.create');
Route::post('/blogs', [BlogController::class, 'store'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.store');
Route::get('/blogs/{blog}/edit', [BlogController::class, 'edit'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.edit');
Route::put('/blogs/{blog}', [BlogController::class, 'update'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.update');
Route::delete('/blogs/{blog}', [BlogController::class, 'destroy'])
    ->middleware(['auth', 'administrator'])
    ->name('blogs.destroy');

/*
|--------------------------------------------------------------------------
| Blade Auth Routes
|--------------------------------------------------------------------------
| This loads Laravel’s built-in auth routes (Breeze/Jetstream/etc.)
| Make sure routes/auth.php exists in your project.
*/
require __DIR__ . '/auth.php';

/*
|--------------------------------------------------------------------------
| Optional CAPTCHA routes (ONLY if you use captcha in Blade forms)
|--------------------------------------------------------------------------
*/
Route::get('/captcha', function () {
    return captcha_img();
})->name('captcha');

Route::get('/captcha-refresh', function () {
    return response()->json(['captcha' => captcha_img()]);
})->name('captcha.refresh');


Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'role:trainer'])->prefix('trainer')->name('trainer.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Trainer\TrainerDashboardController::class, 'home'])->name('home');
    Route::get('/check-in', [\App\Http\Controllers\Trainer\TrainerDashboardController::class, 'checkIn'])->name('check-in');
    Route::get('/subscriptions', [\App\Http\Controllers\Trainer\TrainerDashboardController::class, 'subscriptions'])->name('subscriptions');
    Route::get('/messages', [\App\Http\Controllers\Trainer\TrainerDashboardController::class, 'messages'])->name('messages');
    Route::post('/messages', [\App\Http\Controllers\Trainer\TrainerDashboardController::class, 'sendMessage'])->name('messages.store');
});

Route::middleware(['auth', 'role:user'])->prefix('user')->name('user.')->group(function () {
    Route::get('/', [\App\Http\Controllers\User\UserDashboardController::class, 'home'])->name('home');
    Route::get('/check-in', [\App\Http\Controllers\User\UserDashboardController::class, 'checkIn'])->name('check-in');
    Route::get('/subscriptions', [\App\Http\Controllers\User\UserDashboardController::class, 'subscriptions'])->name('subscriptions');
    Route::get('/messages', [\App\Http\Controllers\User\UserDashboardController::class, 'messages'])->name('messages');
    Route::post('/messages', [\App\Http\Controllers\User\UserDashboardController::class, 'sendMessage'])->name('messages.store');
});


Route::middleware(['auth', 'administrator'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/deleted', [UserController::class, 'deleted'])->name('users.deleted');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}/force', [UserController::class, 'forceDestroy'])->name('users.force-destroy');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{user}/restore', [UserController::class, 'restore'])->name('users.restore');

    Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/', [SubscriptionController::class, 'index'])->name('index');
        Route::post('/', [SubscriptionController::class, 'store'])->name('store');
        Route::get('/options', [SubscriptionController::class, 'options'])->name('options');
        Route::post('/{subscription}/hold', [SubscriptionController::class, 'hold'])->name('hold');
        Route::post('/{subscription}/resume', [SubscriptionController::class, 'resume'])->name('resume');
    });

    Route::get('/messages', [MessageController::class, 'conversations'])->name('messages.conversations');
    Route::get('/messages/{user}', [MessageController::class, 'thread'])->name('messages.thread');
    Route::post('/messages/{user}', [MessageController::class, 'store'])->name('messages.store');
});




/*
|--------------------------------------------------------------------------
| SPA Routes
|--------------------------------------------------------------------------
| Your SPA frontend should live at /app/*
| Example:
|   /app/login
|   /app/dashboard
|   /app/users
|
| Create: resources/views/app.blade.php
| That blade should load your compiled JS/CSS for the SPA.
*/
Route::view('/app/{any?}', 'app')
    ->where('any', '.*')
    ->name('spa');
