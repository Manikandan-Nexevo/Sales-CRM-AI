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

/*
|--------------------------------------------------------------------------
| API Routes - Nexevo Sales CRM
|--------------------------------------------------------------------------
*/

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/users', [UserController::class, 'users']);

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/kpis', [DashboardController::class, 'index']);
    Route::get('/dashboard/team', [DashboardController::class, 'teamStats']);
    Route::get('/dashboard/activity', [DashboardController::class, 'recentActivity']);

    // Contacts (Leads/Prospects)
    Route::apiResource('contacts', ContactController::class);
    Route::get('/contacts/search/quick', [ContactController::class, 'quickSearch']);
    Route::post('/contacts/{contact}/linkedin', [ContactController::class, 'saveLinkedIn']);
    Route::put('/contacts/{contact}/status', [ContactController::class, 'updateStatus']);
    Route::get('/contacts/{contact}/timeline', [ContactController::class, 'timeline']);
    Route::patch('/contacts/{id}/assign', [ContactController::class, 'assign']);

    // Call Logs
    Route::apiResource('calls', CallLogController::class);
    Route::post('/calls/quick-log', [CallLogController::class, 'quickLog']);
    Route::post('/calls/{call}/ai-summary', [CallLogController::class, 'generateAISummary']);
    Route::get('/calls/today', [CallLogController::class, 'todaysCalls']);
    Route::post('/calls/voice-transcript', [CallLogController::class, 'processVoiceTranscript']);

    // Follow-ups
    Route::get('/followups/upcoming', [FollowUpController::class, 'upcomingFollowups']);
    Route::get('/followups/due/today', [FollowUpController::class, 'dueToday']);
    Route::apiResource('followups', FollowUpController::class);

    Route::put('/followups/{followup}/complete', [FollowUpController::class, 'markComplete']);
    Route::post('/followups/{followup}/send-email', [FollowUpController::class, 'sendEmail']);
    Route::post('/followups/{followup}/send-whatsapp', [FollowUpController::class, 'sendWhatsApp']);


    // AI Features
    Route::post('/ai/suggest-response', [AIController::class, 'suggestResponse']);
    Route::post('/ai/analyze-lead', [AIController::class, 'analyzeLead']);
    Route::post('/ai/generate-email', [AIController::class, 'generateEmail']);
    Route::post('/ai/call-summary', [AIController::class, 'callSummary']);
    Route::post('/ai/next-action', [AIController::class, 'suggestNextAction']);
    Route::post('/ai/voice-command', [AIController::class, 'processVoiceCommand']);
    Route::post('/ai/linkedin-message', [AIController::class, 'generateLinkedInMessage']);
    Route::get('/ai/daily-briefing', [AIController::class, 'dailyBriefing']);

    // Export
    Route::get('/export/contacts', [ExportController::class, 'contacts']);
    Route::get('/export/calls', [ExportController::class, 'calls']);
    Route::get('/export/report', [ExportController::class, 'fullReport']);
    Route::post('/contacts/import', [ExportController::class, 'import']);

    // Team Management (Admin)
    Route::middleware('can:admin')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::get('/users/{user}/performance', [UserController::class, 'performance']);
    });
});

Route::post('/ai/send-generated-email', [AIController::class, 'sendGeneratedEmail']);
Route::post('/send-whatsapp', [WhatsappController::class, 'sendWhatsappMessage']);

Route::get('/whatsapp/webhook',  [WhatsappController::class, 'verify']);
Route::post('/whatsapp/webhook', [WhatsappController::class, 'webhook']);

Route::get('/list-messages',  [WhatsappController::class, 'listmessages']);


Route::get('/dashboard/pipeline', [DashboardController::class, 'pipeline']);
Route::get('/dashboard/leaderboard', [DashboardController::class, 'leaderboard']);
Route::get('/dashboard/ai-insights', [DashboardController::class, 'aiInsights']);
Route::get('/dashboard/team-analytics', [DashboardController::class, 'teamAnalytics']);
