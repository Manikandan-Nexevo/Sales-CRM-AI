<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

class SuperAdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'total_companies'         => Company::count(),
            'new_companies_month'     => Company::whereMonth('created_at', now()->month)->count(),
            'total_users'             => User::where('role', '!=', 'superadmin')->count(),
            'active_plans'            => Plan::where('is_active', true)->count(),
            'inactive_plans'          => Plan::where('is_active', false)->count(),
            'mrr'                     => Subscription::where('status', 'active')
                ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                ->sum('plans.price'),
            'monthly_collected'       => Invoice::where('status', 'paid')
                ->whereMonth('paid_date', now()->month)
                ->sum('amount'),
            'active_subscriptions'    => Subscription::where('status', 'active')->count(),
            'trial_subscriptions'     => Subscription::where('status', 'trial')->count(),
            'expired_subscriptions'   => Subscription::where('status', 'expired')->count(),
            'cancelled_subscriptions' => Subscription::where('status', 'cancelled')->count(),
            'overdue_invoices'        => Invoice::where('status', 'overdue')->count(),
            'recent_subscriptions'    => Subscription::with(['company:id,name', 'plan:id,name'])
                ->latest()->limit(5)->get()
                ->map(fn($s) => [
                    'company'    => $s->company?->name ?? '—',
                    'plan'       => $s->plan?->name ?? '—',
                    'status'     => $s->status,
                    'created_at' => $s->created_at->format('d M Y'),
                ]),
        ]);
    }
}

