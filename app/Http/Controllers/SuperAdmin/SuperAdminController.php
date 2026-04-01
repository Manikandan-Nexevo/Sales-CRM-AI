<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;

use Illuminate\Http\JsonResponse;
use App\Services\SuperAdminAIService;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SuperAdminController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $totalCompanies = Company::count();
        $totalUsers = User::where('role', '!=', 'superadmin')->count();

        $newCompaniesThisMonth = Company::whereMonth('created_at', now()->month)->count();

        // Active users = logged in last 7 days
        $activeUsers = User::where('last_login_at', '>=', now()->subDays(7))->count();

        // Inactive companies (no users OR no recent login)
        $inactiveCompanies = Company::where('status', 'inactive')->count();
        $activeCompanies = Company::where('status', 'active')->count();

        return response()->json([
            'total_companies' => Company::count(),
            'active_companies' => $activeCompanies,
            'inactive_companies' => $inactiveCompanies,
            'total_users' => User::count(),
        ]);
    }

    public function insights(): JsonResponse
    {
        $insights = [];

        $inactiveCompanies = Company::whereDoesntHave('users', function ($q) {
            $q->where('last_login_at', '>=', now()->subDays(7));
        })->count();

        if ($inactiveCompanies > 0) {
            $insights[] = [
                'type' => 'warning',
                'message' => "$inactiveCompanies companies are inactive (no user activity in 7 days)"
            ];
        }

        $newUsers = User::whereMonth('created_at', now()->month)->count();

        if ($newUsers > 10) {
            $insights[] = [
                'type' => 'growth',
                'message' => "User growth is strong this month (+$newUsers users)"
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'type' => 'info',
                'message' => "System is stable. No critical alerts."
            ];
        }

        return response()->json($insights);
    }

    public function companyHealth(): JsonResponse
    {
        $companies = Company::withCount('users')->get()->map(function ($company) {

            $activeUsers = User::where('company_id', $company->id)
                ->where('last_login_at', '>=', now()->subDays(7))
                ->count();

            $status = 'healthy';

            if ($company->users_count == 0) {
                $status = 'dead';
            } elseif ($activeUsers == 0) {
                $status = 'risk';
            }

            return [
                'id' => $company->id,
                'name' => $company->name,
                'users' => $company->users_count,
                'active_users' => $activeUsers,
                'status' => $status,
            ];
        });

        return response()->json($companies);
    }

    public function analytics()
    {
        $days = collect(range(0, 6))->map(function ($i) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');

            return [
                'date'  => $date,
                'users' => User::whereDate('created_at', $date)->count(),
            ];
        })->reverse()->values();

        return response()->json($days);
    }

    public function aiQuery(Request $request, SuperAdminAIService $ai)
    {
        $result = $ai->ask($request->input('query'));

        // 🔥 HANDLE ACTIONS
        if ($result['type'] === 'action') {

            switch ($result['action']) {

                case 'delete_company':

                    $name = $result['payload']['name'];

                    Company::where('name', $name)->delete();

                    // ✅ LOG ACTION
                    ActivityLog::create([
                        'user_id' => Auth::id(),
                        'action' => 'delete_company',
                        'description' => "Deleted company: $name"
                    ]);

                    return response()->json([
                        'type' => 'success',
                        'message' => "Company deleted"
                    ]);

                case 'delete_company':

                    $name = $result['payload']['name'];

                    Company::where('name', $name)->delete();

                    // ✅ LOG ACTION
                    ActivityLog::create([
                        'user_id' => Auth::id(),
                        'action' => 'delete_company',
                        'description' => "Deleted company: $name"
                    ]);

                    return response()->json([
                        'type' => 'success',
                        'message' => "Company deleted"
                    ]);

                case 'list_companies':
                    return response()->json([
                        'type' => 'data',
                        'data' => Company::select('id', 'name', 'status')->get()
                    ]);
            }
        }

        return response()->json($result);
    }

    public function companiesPreview(Request $request)
    {
        return Company::withCount('users')
            ->latest()

            ->get()
            ->map(function ($c) {
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'status' => $c->status,
                    'plan' => $c->plan ?? 'free',
                    'users_count' => $c->users_count,
                    'created_at' => $c->created_at,
                ];
            });
    }
    public function activity()
    {
        $logs = \App\Models\ActivityLog::latest()->limit(10)->get();

        return response()->json([
            'data' => $logs->map(fn($log) => [
                'id' => $log->id,
                'text' => $log->description,
                'time' => \Carbon\Carbon::parse($log->created_at)->diffForHumans(),
                'action' => $log->action,
            ])
        ]);
    }

    public function systemHealth()
    {
        return response()->json([
            'total_users' => User::count(),
            'active_users' => User::where('last_login_at', '>=', now()->subDays(7))->count(),
            'inactive_users' => User::whereNull('last_login_at')->count(),
            'total_companies' => Company::count(),
            'active_companies' => Company::where('status', 'active')->count(),
        ]);
    }
}
