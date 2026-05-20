<?php

namespace App\Http\Controllers;

use App\Models\RolesPermission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

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

        if (!$user || !\Illuminate\Support\Facades\Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        // ❌ user disabled
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Your account is disabled.'
            ], 403);
        }

        // ❌ Check Business Suite permission (except for superadmin)
        if ($user->normalized_role !== 'superadmin') {
            $company = $user->company;
            if (!$company) {
                return response()->json([
                    'message' => 'Your account does not have permission to access the Sales CRM. Please contact the administrator'
                ], 403);
            }

            $businessSuite = $company->businessSuite;
            if (!$businessSuite || $businessSuite->sales_crm != 1) {
                return response()->json([
                    'message' => 'Your account does not have permission to access the Sales CRM. Please contact the administrator'
                ], 403);
            }
        }

        // ❌ company inactive
        if ($user->company && $user->company->status === 'inactive') {
            return response()->json([
                'message' => 'Your company is inactive. Please contact super admin.'
            ], 403);
        }

        // roles and permission
        $roles_permission = RolesPermission::on('mysql')
            ->where('user_id', $user->id)
            ->first();

        // ❌ Check permission only for admin & sales_rep
        if (
            in_array($user->normalized_role, ['admin', 'sales_rep']) &&
            !$roles_permission
        ) {
            return response()->json([
                'message' => 'Your account does not have permission to access the Sales CRM. Please contact the administrator.'
            ], 403);
        }

        // Track last login timestamp
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        $redirect = $user->normalized_role === 'superadmin' ? '/super' : '/';

        return response()->json([
            'success'          => true,
            'user'             => $user,
            'roles_permission' => $roles_permission,
            'token'            => $token,
            'redirect'         => $redirect,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['success' => true, 'message' => 'Logged out successfully']);
    }

    public function savePermissions(Request $request)
    {
        $request->validate([
            'user_id' => 'required'
        ]);

        $userId = $request->user_id;

        // Helper function → check any value = 1
        $isEnabled = function ($array) {
            return collect($array)->contains(1) ? 1 : 0;
        };

        $contactsArr = [
            'add'    => $request->contacts['add'] ?? 0,
            'edit'   => $request->contacts['edit'] ?? 0,
            'view'   => $request->contacts['view'] ?? 0,
            'delete' => $request->contacts['delete'] ?? 0,
            'export' => $request->contacts['export'] ?? 0,
            'import' => $request->contacts['import'] ?? 0,
        ];
        $contactsArr['contacts'] = $isEnabled($contactsArr);

        $callLogsArr = [
            'view'   => $request->call_logs['view'] ?? 0,
            'log'    => $request->call_logs['log'] ?? 0,
            'export' => $request->call_logs['export'] ?? 0,
        ];
        $callLogsArr['call_logs'] = $isEnabled($callLogsArr);

        $followUpsArr = [
            'view' => $request->follow_ups['view'] ?? 0,
            'ai'   => $request->follow_ups['ai'] ?? 0,
        ];
        $followUpsArr['follow_ups'] = $isEnabled($followUpsArr);

        $teamsArr = [
            'add' => $request->teams['add'] ?? 0,
        ];
        $teamsArr['teams'] = $isEnabled($teamsArr);

        $salesCrmData = [
            'contacts'     => $contactsArr,
            'call_logs'    => $callLogsArr,
            'follow_ups'   => $followUpsArr,
            'teams'        => $teamsArr,

            'availability' => $request->availability ?? 0,
            'my_bookings'  => $request->my_bookings ?? 0,
            'settings'     => $request->settings ?? 0,

            'whatsapp'     => $request->whatsapp ?? 0,
            'ai_assistant' => $request->ai_assistant ?? 0,
            'email'        => $request->email ?? 0,
        ];

        $pmData = [
            'backlog'      => $request->backlog ?? 0,
            'board'        => $request->board ?? 0,
            'gantt'        => $request->gantt ?? 0,
            'pm_audit'     => $request->pm_audit ?? 0,
            'timetracking' => $request->timetracking ?? 0,
            'pm_roles'     => $request->pm_roles ?? 0,
            'pm_system'    => $request->pm_system ?? 0,
            'pm_settings'  => $request->pm_settings ?? 0,
            'pm_users'     => $request->pm_users ?? 0,
            'projects'     => $request->projects ?? 0,
            'reports'      => $request->reports ?? 0,
            'sprints'      => $request->sprints ?? 0,
        ];

        $data = [
            'sales_crm'               => json_encode($salesCrmData),
            'project_management_tool' => json_encode($pmData),
            'updated_at'              => now(),
        ];

        $exists = DB::table('roles_permissions')
            ->where('user_id', $userId)
            ->first();

        if ($exists) {
            DB::table('roles_permissions')
                ->where('user_id', $userId)
                ->update($data);
        } else {
            $data['user_id'] = $userId;
            $data['created_at'] = now();

            DB::table('roles_permissions')->insert($data);
        }

        return response()->json([
            'status' => true,
            'message' => 'Permissions saved successfully'
        ]);
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
