<?php

namespace App\Http\Controllers;

use App\Models\CallLog;
use App\Models\Contact;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $baseQuery = $isAdmin
            ? CallLog::query()
            : CallLog::where('user_id', $user->id);

        $contactQuery = $isAdmin
            ? Contact::query()
            : Contact::where('assigned_to', $user->id);

        return response()->json([
            'kpis' => $this->getKpis($user, $isAdmin),
            'recent_calls' => $baseQuery->with('contact')->latest()->take(5)->get(),
            'followups_due' => $this->getDueFollowups($user, $isAdmin),
            'top_contacts' => $contactQuery->where('priority', 'high')->take(5)->get(),
            'weekly_trend' => $this->getWeeklyTrend($user, $isAdmin),
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        return response()->json($this->getKpis($user, $isAdmin));
    }

    public function teamStats(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $stats = User::where('role', 'sales_rep')
            ->where('is_active', true)
            ->withCount(['callLogs as today_calls' => function ($q) {
                $q->whereDate('created_at', today());
            }])
            ->withCount(['callLogs as total_calls'])
            ->withCount(['contacts as total_leads'])
            ->get();

        return response()->json($stats);
    }

    public function recentActivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $calls = ($isAdmin ? CallLog::query() : CallLog::where('user_id', $user->id))
            ->with(['contact', 'user'])
            ->latest()
            ->take(20)
            ->get()
            ->map(function ($call) {
                return [
                    'type' => 'call',
                    'id' => $call->id,
                    'contact' => $call->contact?->name,
                    'company' => $call->contact?->company,
                    'outcome' => $call->outcome,
                    'user' => $call->user?->name,
                    'time' => $call->created_at->diffForHumans(),
                    'created_at' => $call->created_at,
                ];
            });

        return response()->json($calls);
    }

    private function getKpis(User $user, bool $isAdmin): array
    {
        $callQuery = $isAdmin ? CallLog::query() : CallLog::where('user_id', $user->id);
        $contactQuery = $isAdmin ? Contact::query() : Contact::where('assigned_to', $user->id);
        $followupQuery = $isAdmin ? FollowUp::query() : FollowUp::where('user_id', $user->id);

        $todayCalls = (clone $callQuery)->whereDate('created_at', today())->count();
        $todayConnected = (clone $callQuery)->whereDate('created_at', today())->where('status', 'connected')->count();
        $targetCalls = $isAdmin ? User::sum('target_calls_daily') : $user->target_calls_daily;
        $conversionRate = $todayCalls > 0 ? round(($todayConnected / $todayCalls) * 100) : 0;

        return [
            'today_calls' => $todayCalls,
            'today_connected' => $todayConnected,
            'target_calls' => $targetCalls,
            'call_progress' => $targetCalls > 0 ? min(100, round(($todayCalls / $targetCalls) * 100)) : 0,
            'conversion_rate' => $conversionRate,
            'total_leads' => (clone $contactQuery)->count(),
            'hot_leads' => (clone $contactQuery)->where('status', 'hot')->count(),
            'qualified_leads' => (clone $contactQuery)->where('status', 'qualified')->count(),
            'pending_followups' => (clone $followupQuery)->where('status', 'pending')->whereDate('scheduled_at', '<=', today())->count(),
            'overdue_followups' => (clone $followupQuery)->where('status', 'pending')->whereDate('scheduled_at', '<', today())->count(),
            'avg_call_duration' => round((clone $callQuery)->whereDate('created_at', today())->avg('duration') ?? 0),
            'this_week_calls' => (clone $callQuery)->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month_calls' => (clone $callQuery)->whereMonth('created_at', now()->month)->count(),
            'new_leads_today' => (clone $contactQuery)->whereDate('created_at', today())->count(),
        ];
    }

    private function getDueFollowups(User $user, bool $isAdmin): array
    {
        $query = $isAdmin ? FollowUp::query() : FollowUp::where('user_id', $user->id);
        return $query->with('contact')
            ->where('status', 'pending')
            ->whereDate('scheduled_at', '<=', today())
            ->orderBy('scheduled_at')
            ->take(10)
            ->get()
            ->toArray();
    }

    private function getWeeklyTrend(User $user, bool $isAdmin): array
    {
        $query = $isAdmin ? CallLog::query() : CallLog::where('user_id', $user->id);

        return $query->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [now()->subDays(7), now()])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }
}
