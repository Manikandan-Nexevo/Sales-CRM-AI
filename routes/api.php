<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CallLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\SuperAdmin\CompanyController;
use App\Http\Controllers\SuperAdmin\SuperUserController;
use App\Http\Controllers\SuperAdmin\PlanController;
use App\Http\Controllers\SuperAdmin\SubscriptionController;
use App\Http\Controllers\SuperAdmin\InvoiceController;
use App\Http\Controllers\SuperAdmin\TransactionController;
use App\Http\Controllers\SuperAdmin\RoleController;
use App\Http\Controllers\SuperAdmin\SettingsController;


Route::prefix('auth')->group(function () {
    Route::post('/login',    [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

// Meeting booking
Route::get('/book/{slug}',               [BookingController::class, 'getAvailability']);
Route::post('/book/{slug}',              [BookingController::class, 'book']);
Route::post('/book/{slug}/reschedule',   [BookingController::class, 'reschedule']);
Route::post('/book/{slug}/cancel',       [BookingController::class, 'cancel']);
Route::get('/book/{slug}/info', [BookingController::class, 'hostInfo']);

// WhatsApp webhook
Route::post('/send-whatsapp',            [WhatsappController::class, 'sendWhatsappMessage']);
Route::get('/whatsapp/webhook',          [WhatsappController::class, 'verify']);
Route::post('/whatsapp/webhook',         [WhatsappController::class, 'webhook']);
Route::get('/list-messages',             [WhatsappController::class, 'listmessages']);

// Dashboard routes
Route::get('/dashboard/pipeline',        [DashboardController::class, 'pipeline']);
Route::get('/dashboard/leaderboard',     [DashboardController::class, 'leaderboard']);
Route::get('/dashboard/ai-insights',     [DashboardController::class, 'aiInsights']);
Route::get('/dashboard/team-analytics',  [DashboardController::class, 'teamAnalytics']);

Route::get('/google/callback', [GoogleAuthController::class, 'callback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',   [AuthController::class, 'logout']);
    Route::get('/auth/me',        [AuthController::class, 'me']);
    Route::put('/auth/profile',   [AuthController::class, 'updateProfile']);


    Route::get('/internal/slots',    [BookingController::class, 'getSlotsInternal']);
    Route::post('/internal/book',    [BookingController::class, 'bookInternal']);
    Route::post('/internal/reschedule', [BookingController::class, 'rescheduleInternal']);
    Route::post('/internal/cancel',     [BookingController::class, 'cancelInternal']);

    Route::post('/booking-link',     [BookingController::class, 'createLink']);
    Route::get('/my-bookings',       [BookingController::class, 'myBookings']);
    Route::post('/availability',     [BookingController::class, 'setAvailability']);
    Route::get('/availability',      [BookingController::class, 'getAvailabilitySettings']);

    Route::get('/google/connect',       [GoogleAuthController::class, 'connect']);
    Route::get('/google/status',        [GoogleAuthController::class, 'status']);
    Route::post('/google/disconnect',   [GoogleAuthController::class, 'disconnect']);

    Route::get('/meeting-token/{roomName}', [BookingController::class, 'getMeetingToken']);

    Route::middleware('role:superadmin')->prefix('super')->group(function () {
        Route::get('dashboard',                                       [SuperAdminController::class, 'dashboard']);
        Route::get('insights',                                        [SuperAdminController::class, 'insights']);
        Route::get('company-health',                                  [SuperAdminController::class, 'companyHealth']);
        Route::get('analytics',                                       [SuperAdminController::class, 'analytics']);
        Route::post('ai-query',                                       [SuperAdminController::class, 'aiQuery']);
        Route::get('/companies-preview',                              [SuperAdminController::class, 'companiesPreview']);
        Route::get('activity',                                        [SuperAdminController::class, 'activity']);
        Route::get('system-health',                                   [SuperAdminController::class, 'systemHealth']);
        Route::apiResource('companies',                               CompanyController::class);
        Route::apiResource('users',                                   SuperUserController::class);
        Route::apiResource('plans',                                   PlanController::class);
        Route::apiResource('subscriptions',                           SubscriptionController::class);
        Route::post('subscriptions/{subscription}/cancel',            [SubscriptionController::class, 'cancel']);
        Route::get('invoices',                                        [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}',                              [InvoiceController::class, 'show']);
        Route::get('invoices/{invoice}/pdf',                          [InvoiceController::class, 'downloadPdf']);
        Route::get('transactions',                                    [TransactionController::class, 'index']);
        Route::apiResource('roles',                                   RoleController::class);
        Route::get('roles/{role}/users',    [RoleController::class, 'users']);
        Route::patch('roles/{role}/assign', [RoleController::class, 'assign']);
        Route::get('settings',                                        [SettingsController::class, 'index']);
        Route::put('settings',                                        [SettingsController::class, 'update']);
    });

    // General Routes
    Route::get('/users',              [UserController::class, 'users']);
    Route::get('/calendar',           [CalendarController::class, 'index']);
    Route::post('/ai/send-generated-email', [AIController::class, 'sendGeneratedEmail']);

    // Dashboard Routes
    Route::get('/dashboard',               [DashboardController::class, 'index']);
    Route::get('/dashboard/kpis',          [DashboardController::class, 'index']);
    Route::get('/dashboard/team',          [DashboardController::class, 'teamStats']);
    Route::get('/dashboard/activity',      [DashboardController::class, 'recentActivity']);

    // Contacts Routes
    Route::apiResource('contacts',         ContactController::class);
    Route::get('/contacts/search/quick',   [ContactController::class, 'quickSearch']);
    Route::post('/contacts/{contact}/linkedin', [ContactController::class, 'saveLinkedIn']);
    Route::put('/contacts/{contact}/status',    [ContactController::class, 'updateStatus']);
    Route::get('/contacts/{id}/timeline',       [ContactController::class, 'timeline']);
    Route::patch('/contacts/{id}/assign',       [ContactController::class, 'assign']);

    // Calls Routes
    Route::apiResource('calls',            CallLogController::class);
    Route::post('/calls/quick-log',        [CallLogController::class, 'quickLog']);
    Route::post('/calls/{call}/ai-summary', [CallLogController::class, 'generateAISummary']);
    Route::get('/calls/today',             [CallLogController::class, 'todaysCalls']);
    Route::post('/calls/voice-transcript', [CallLogController::class, 'processVoiceTranscript']);
    Route::post('/calls/preview-summary', [CallLogController::class, 'previewSummary']);

    // Follow-ups Routes
    Route::get('/followups/upcoming',      [FollowUpController::class, 'upcomingFollowups']);
    Route::get('/followups/due/today',     [FollowUpController::class, 'dueToday']);
    Route::apiResource('followups', FollowUpController::class)->parameters(['followups' => 'id']);
    Route::put('/followups/{id}/complete', [FollowUpController::class, 'markComplete']);
    Route::post('/followups/{id}/send-email',     [FollowUpController::class, 'sendEmail']);
    Route::post('/followups/{id}/send-whatsapp',  [FollowUpController::class, 'sendWhatsApp']);

    // AI Routes
    Route::post('/ai/suggest-response',   [AIController::class, 'suggestResponse']);
    Route::post('/ai/analyze-lead',       [AIController::class, 'analyzeLead']);
    Route::post('/ai/generate-email',     [AIController::class, 'generateEmail']);
    Route::post('/ai/call-summary',       [AIController::class, 'callSummary']);
    Route::post('/ai/next-action',        [AIController::class, 'suggestNextAction']);
    Route::post('/ai/voice-command',      [AIController::class, 'processVoiceCommand']);
    Route::post('/ai/linkedin-message',   [AIController::class, 'generateLinkedInMessage']);
    Route::get('/ai/daily-briefing',      [AIController::class, 'dailyBriefing']);

    // Export Routes
    Route::get('/export/contacts',         [ExportController::class, 'contacts']);
    Route::get('/export/calls',            [ExportController::class, 'calls']);
    Route::get('/export/report',           [ExportController::class, 'fullReport']);
    Route::post('/contacts/import',        [ExportController::class, 'import']);

    Route::middleware('can:admin')->group(function () {
        Route::apiResource('users',                   UserController::class);
        Route::get('/users/{user}/performance',       [UserController::class, 'performance']);
    });
});
