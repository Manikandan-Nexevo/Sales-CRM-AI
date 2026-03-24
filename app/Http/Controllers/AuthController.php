<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role'     => 'sometimes|in:admin,sales_rep,manager',
            // NOTE: superadmin is NEVER created via API — only via seeder
        ]);

        $user = User::create([
            'name'                 => $request->name,
            'email'                => $request->email,
            'password'             => Hash::make($request->password),
            'role'                 => $request->role ?? 'sales_rep',
            'target_calls_daily'   => 50,
            'target_leads_monthly' => 10,
            'is_active'            => true,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success'  => true,
            'user'     => $user,
            'token'    => $token,
            'redirect' => '/',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Account is disabled.'], 403);
        }

        // Track last login timestamp
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        // ── KEY CHANGE: redirect field tells frontend where to go ──
        $redirect = $user->role === 'superadmin' ? '/super' : '/';

        return response()->json([
            'success'  => true,
            'user'     => $user,
            'token'    => $token,
            'redirect' => $redirect,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('company');
        return response()->json([
            'user'              => $user,
            'today_calls'       => method_exists($user, 'todayCallCount') ? $user->todayCallCount() : 0,
            'pending_followups' => $user->followUps()
                ->where('status', 'pending')
                ->whereDate('scheduled_at', '<=', today())
                ->count(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'name'                 => 'sometimes|string|max:255',
            'phone'                => 'nullable|string|max:20',
            'target_calls_daily'   => 'nullable|integer|min:1',
            'target_leads_monthly' => 'nullable|integer|min:1',
        ]);

        $user->update($request->only(['name', 'phone', 'target_calls_daily', 'target_leads_monthly']));

        return response()->json(['success' => true, 'user' => $user]);
    }
}
