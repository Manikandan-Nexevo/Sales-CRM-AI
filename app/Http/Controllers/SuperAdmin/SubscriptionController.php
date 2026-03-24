<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::with(['company:id,name', 'plan:id,name,price']);

        if ($s = $request->search) {
            $query->whereHas('company', fn($q) => $q->where('name', 'like', "%$s%"))
                ->orWhereHas('plan',    fn($q) => $q->where('name', 'like', "%$s%"));
        }

        $paginated = $query->latest()->paginate($request->per_page ?? 10);
        $paginated->getCollection()->transform(fn($s) => array_merge(
            $s->toArray(),
            ['company_name' => $s->company?->name, 'plan_name' => $s->plan?->name]
        ));

        $stats = [
            'active'    => Subscription::where('status', 'active')->count(),
            'trial'     => Subscription::where('status', 'trial')->count(),
            'expired'   => Subscription::where('status', 'expired')->count(),
            'cancelled' => Subscription::where('status', 'cancelled')->count(),
            'mrr'       => Subscription::where('status', 'active')
                ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                ->sum('plans.price'),
        ];

        return response()->json(array_merge($paginated->toArray(), ['stats' => $stats]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id'     => 'required|exists:companies,id',
            'plan_id'        => 'required|exists:plans,id',
            'status'         => 'required|in:active,trial,cancelled',
            'start_date'     => 'required|date',
            'end_date'       => 'required|date|after:start_date',
            'payment_method' => 'nullable|string',
            'amount_paid'    => 'nullable|numeric',
            'auto_renew'     => 'boolean',
        ]);

        $sub = Subscription::create($data);
        activity()->causedBy(auth()->user())->performedOn($sub)->log("Created subscription for company_id={$data['company_id']}");

        return response()->json($sub->load(['company:id,name', 'plan:id,name']), 201);
    }

    public function update(Request $request, Subscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'status'         => 'sometimes|in:active,trial,cancelled,expired',
            'end_date'       => 'sometimes|date',
            'payment_method' => 'nullable|string',
            'amount_paid'    => 'nullable|numeric',
            'auto_renew'     => 'boolean',
        ]);

        $subscription->update($data);
        return response()->json($subscription);
    }

    public function cancel(Subscription $subscription): JsonResponse
    {
        $subscription->update(['status' => 'cancelled', 'auto_renew' => false]);
        activity()->causedBy(auth()->user())->performedOn($subscription)->log("Cancelled subscription id={$subscription->id}");
        return response()->json(['success' => true]);
    }
}
