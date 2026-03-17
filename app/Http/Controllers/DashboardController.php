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

        return response()->json([

            'kpis' => $this->getKpis($user, $isAdmin),

            'followups_due' => $this->getDueFollowups($user, $isAdmin),

            'weekly_trend' => $this->getWeeklyTrend($user, $isAdmin),

            'leaderboard' => $isAdmin ? $this->getLeaderboard() : [],

            'is_admin' => $isAdmin

        ]);
    }


    public function recentActivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();

        $callQuery = $isAdmin
            ? CallLog::query()
            : CallLog::where('user_id', $user->id);

        $followQuery = $isAdmin
            ? FollowUp::query()
            : FollowUp::where('user_id', $user->id);

        $contactQuery = $isAdmin
            ? Contact::query()
            : Contact::where('assigned_to', $user->id);

        $calls = $callQuery
            ->with('contact')
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($call) {

                return [
                    'id' => $call->id,
                    'type' => 'call',
                    'contact' => $call->contact?->name,
                    'company' => $call->contact?->company,
                    'outcome' => $call->outcome ?? 'Call logged',
                    'time' => $call->created_at->diffForHumans(),
                    'event_time' => $call->created_at->format('d M y, g:i A'),
                    'created_at' => $call->created_at
                ];
            });
        $followups = $followQuery
            ->with('contact')
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($fu) {

                return [
                    'id' => $fu->id,
                    'type' => 'followup',
                    'contact' => $fu->contact?->name,
                    'company' => $fu->contact?->company,
                    'outcome' => 'Follow-up scheduled',
                    'time' => $fu->created_at->diffForHumans(),
                    'event_time' => $fu->scheduled_at?->format('d M y, g:i A'),
                    'created_at' => $fu->created_at
                ];
            });

        $contacts = $contactQuery
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($c) {
                return [
                    'type' => 'lead',
                    'contact' => $c->name,
                    'company' => $c->company,
                    'outcome' => 'New lead added',
                    'time' => $c->created_at->diffForHumans(),
                    'created_at' => $c->created_at
                ];
            });

        $activity = collect()
            ->merge($calls)
            ->merge($followups)
            ->merge($contacts)
            ->sortByDesc('created_at')
            ->take(15)
            ->values();

        return response()->json($activity);
    }
    private function getKpis(User $user, bool $isAdmin): array
    {

        $callQuery = $isAdmin ? CallLog::query() : CallLog::where('user_id', $user->id);
        $contactQuery = $isAdmin ? Contact::query() : Contact::where('assigned_to', $user->id);
        $followQuery = $isAdmin ? FollowUp::query() : FollowUp::where('user_id', $user->id);

        $todayCalls = (clone $callQuery)->whereDate('created_at', today())->count();
        $connected = (clone $callQuery)->whereDate('created_at', today())->where('status', 'connected')->count();

        $target = $isAdmin ? User::sum('target_calls_daily') : $user->target_calls_daily;

        return [

            'today_calls' => $todayCalls,
            'today_connected' => $connected,
            'target_calls' => $target,

            'call_progress' => $target > 0
                ? round(($todayCalls / $target) * 100)
                : 0,

            'conversion_rate' => $todayCalls > 0
                ? round(($connected / $todayCalls) * 100)
                : 0,

            'total_leads' => (clone $contactQuery)->count(),

            'hot_leads' => (clone $contactQuery)
                ->where('status', 'hot')
                ->count(),

            'qualified_leads' => (clone $contactQuery)
                ->where('status', 'qualified')
                ->count(),

            'pending_followups' => (clone $followQuery)
                ->where('status', 'pending')
                ->whereDate('scheduled_at', '<=', today())
                ->count(),

            'overdue_followups' => (clone $followQuery)
                ->where('status', 'pending')
                ->whereDate('scheduled_at', '<', today())
                ->count(),

            'avg_call_duration' => round(
                (clone $callQuery)
                    ->whereDate('created_at', today())
                    ->avg('duration') ?? 0
            ),

            'this_week_calls' => (clone $callQuery)
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),

            'this_month_calls' => (clone $callQuery)
                ->whereMonth('created_at', now()->month)
                ->count(),

            'new_leads_today' => (clone $contactQuery)
                ->whereDate('created_at', today())
                ->count(),

        ];
    }

    private function getPipeline(): array
    {
        return Contact::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->get()
            ->toArray();
    }

    public function teamStats()
    {
        $users = User::where('role', 'sales_rep')
            ->where('company_id', auth()->user()->company_id)
            ->get();

        $team = $users->map(function ($user) {

            $todayCalls = CallLog::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            $totalCalls = CallLog::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $followups = FollowUp::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $leads = Contact::where('assigned_to', $user->id)->count();

            $target = $user->target_calls_daily ?? 50;

            $engagementScore =
                ($totalCalls * 1) +
                ($followups * 2) +
                ($leads * 0.5);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,

                'today_calls' => $todayCalls,
                'total_calls' => $totalCalls,
                'total_leads' => $leads,

                'target' => $target,

                'progress' => $target > 0
                    ? round(($todayCalls / $target) * 100)
                    : 0,

                'engagement_score' => round($engagementScore)
            ];
        });

        return response()->json($team);
    }

    public function teamAnalytics(): JsonResponse
    {
        $users = User::where('role', 'sales_rep')
            ->where('company_id', auth()->user()->company_id)
            ->get();

        $data = $users->map(function ($user) {

            $calls7 = CallLog::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $todayCalls = CallLog::where('user_id', $user->id)
                ->whereDate('created_at', today())
                ->count();

            $connected = CallLog::where('user_id', $user->id)
                ->where('status', 'connected')
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $followups = FollowUp::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subDays(7))
                ->count();

            $leads = Contact::where('assigned_to', $user->id)->count();

            $score =
                ($calls7 * 1) +
                ($connected * 2) +
                ($followups * 1.5);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'calls_7_days' => $calls7,
                'today_calls' => $todayCalls,
                'connected' => $connected,
                'followups' => $followups,
                'leads' => $leads,
                'productivity_score' => round($score),
            ];
        });

        return response()->json($data);
    }

    private function getLeaderboard(): array
    {
        return User::where('role', 'sales_rep')
            ->where('company_id', auth()->user()->company_id)
            ->withCount([
                'callLogs as calls_today' => function ($q) {
                    $q->whereDate('created_at', today());
                }
            ])
            ->orderByDesc('calls_today')
            ->take(5)
            ->get()
            ->toArray();
    }

    private function getAIInsights(): array
    {

        $overdueHot = Contact::where('status', 'hot')
            ->where('last_contacted_at', '<', now()->subDays(2))
            ->count();

        $bestHour = DB::table('call_logs')
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as total')
            ->where('status', 'connected')
            ->groupBy('hour')
            ->orderByDesc('total')
            ->first();

        return [

            [
                'type' => 'warning',
                'message' => "$overdueHot hot leads not contacted in 48 hours"
            ],

            [
                'type' => 'insight',
                'message' => 'Best call success hour: ' . ($bestHour->hour ?? 11) . ':00'
            ],

            [
                'type' => 'tip',
                'message' => 'Follow up within 24 hours increases close rate by 40%'
            ]

        ];
    }

    private function getDueFollowups(User $user, bool $isAdmin): array
    {
        $query = $isAdmin
            ? FollowUp::query()
            : FollowUp::where('user_id', $user->id);

        return $query
            ->with('contact')
            ->where('status', 'pending')
            ->whereDate('scheduled_at', '<=', today())
            ->orderBy('scheduled_at')
            ->take(10)
            ->get()
            ->toArray();
    }

    private function getWeeklyTrend(User $user, bool $isAdmin): array
    {

        $query = $isAdmin
            ? CallLog::query()
            : CallLog::where('user_id', $user->id);

        return $query
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as total'))
            ->whereBetween('created_at', [now()->subDays(7), now()])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }
}
