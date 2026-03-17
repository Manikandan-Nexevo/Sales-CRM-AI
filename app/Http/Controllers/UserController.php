<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CallLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $users = User::where('company_id', $companyId)
            ->withCount(['callLogs as today_calls' => function ($q) {
                $q->whereDate('created_at', today());
            }])
            ->withCount('contacts as total_contacts')
            ->get();

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role' => 'required|in:admin,sales_rep,manager',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'company_id' => auth()->user()->company_id, // 🔥 CRITICAL
            'target_calls_daily' => $request->target_calls_daily ?? 50,
            'target_leads_monthly' => $request->target_leads_monthly ?? 10,
            'is_active' => true,
        ]);

        return response()->json(['success' => true, 'user' => $user], 201);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount(['callLogs', 'contacts']);
        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $user->update($request->except('password'));
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->update(['is_active' => false]);
        return response()->json(['success' => true]);
    }

    public function performance(User $user): JsonResponse
    {
        $calls = CallLog::where('user_id', $user->id);

        return response()->json([
            'user' => $user,
            'today_calls' => (clone $calls)->whereDate('created_at', today())->count(),
            'week_calls' => (clone $calls)->whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'month_calls' => (clone $calls)->whereMonth('created_at', now()->month)->count(),
            'conversion_rate' => $this->calcConversion($user->id),
            'avg_duration' => round((clone $calls)->avg('duration') ?? 0),
        ]);
    }

    private function calcConversion(int $userId): float
    {
        $total = CallLog::where('user_id', $userId)->count();
        $connected = CallLog::where('user_id', $userId)->where('status', 'connected')->count();
        return $total > 0 ? round(($connected / $total) * 100, 1) : 0;
    }

    public function users()
    {
        return User::where('company_id', auth()->user()->company_id)
            ->where('is_active', true)
            ->select('id', 'name')
            ->get();
    }
}
