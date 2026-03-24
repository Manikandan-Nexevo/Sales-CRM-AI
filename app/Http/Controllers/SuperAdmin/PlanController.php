<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Plan::withCount('activeSubscriptions as subscribers_count')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|unique:plans,name',
            'description'   => 'nullable|string',
            'price'         => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:monthly,yearly',
            'max_users'     => 'required|integer|min:-1',
            'max_leads'     => 'required|integer|min:-1',
            'trial_days'    => 'required|integer|min:0',
            'is_active'     => 'boolean',
            'features'      => 'array',
        ]);

        $plan = Plan::create($data);
        activity()->causedBy(auth()->user())->performedOn($plan)->log("Created plan: {$plan->name}");

        return response()->json($plan, 201);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $request->validate([
            'name'          => "sometimes|string|unique:plans,name,{$plan->id}",
            'description'   => 'nullable|string',
            'price'         => 'sometimes|numeric|min:0',
            'billing_cycle' => 'sometimes|in:monthly,yearly',
            'max_users'     => 'sometimes|integer|min:-1',
            'max_leads'     => 'sometimes|integer|min:-1',
            'trial_days'    => 'sometimes|integer|min:0',
            'is_active'     => 'boolean',
            'features'      => 'array',
        ]);

        $plan->update($data);
        return response()->json($plan);
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->activeSubscriptions()->exists()) {
            return response()->json(['message' => 'Cannot delete plan with active subscribers.'], 422);
        }
        $plan->delete();
        return response()->json(['success' => true]);
    }
}
