<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class SuperUserController extends Controller
{
    private function formatDate(?string $date): ?string
    {
        if (!$date) return null;
        try {
            return \Carbon\Carbon::parse($date)->format('d-m-Y h:i:s A');
        } catch (\Exception $e) {
            return $date;
        }
    }

    private function transform(User $u): array
    {
        $data = array_merge(
            $u->toArray(),
            [
                'company_name'  => $u->company?->name,
                'created_at'    => $this->formatDate($u->created_at),
                'updated_at'    => $this->formatDate($u->updated_at),
                'last_login_at' => $this->formatDate($u->last_login_at ?? null),
                'sales_crm'     => $u->company?->businessSuite?->sales_crm,
                'project_management_tool' => $u->company?->businessSuite?->project_managment_tool,
            ]
        );

        unset($data['password']);

        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::with(['company:id,name,phone', 'company.businessSuite'])
            ->where('role', '!=', 'superadmin');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->company_id);
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;

            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhereHas('company', function ($q2) use ($s) {
                        $q2->where('name', 'like', "%$s%");
                    });
            });
        }

        $users = $query->latest()->paginate($request->per_page ?? 10);

        $userIds = $users->pluck('id');

        $permissions = DB::table('roles_permissions')
            ->whereIn('user_id', $userIds)
            ->get()
            ->keyBy('user_id');

        $collection = $users->getCollection()->map(function ($u) use ($permissions) {

            $perm = $permissions[$u->id] ?? null;

            $salesCrm = $perm && $perm->sales_crm ? json_decode($perm->sales_crm, true) : null;
            $pm = $perm && $perm->project_management_tool ? json_decode($perm->project_management_tool, true) : null;

            return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role,
                    'status' => $u->is_active == 1 ? 'Active' : 'Inactive',
                    'created_at' => $this->formatDate($u->created_at),
                    'company' => $u->company ? [
                        'id' => $u->company->id,
                        'name' => $u->company->name,
                        'phone' => $u->company->phone,
                        'sales_crm' => $u->company->businessSuite?->sales_crm,
                        'project_management_tool' => $u->company->businessSuite?->project_managment_tool
                    ] : null,

                'permissions' => ($salesCrm || $pm) ? [
                    // Sales CRM
                    'contacts'    => $salesCrm['contacts'] ?? [],
                    'call_logs'   => $salesCrm['call_logs'] ?? [],
                    'follow_ups'  => $salesCrm['follow_ups'] ?? [],
                    'teams'       => $salesCrm['teams'] ?? [],

                    'availability' => $salesCrm['availability'] ?? 0,
                    'my_bookings'  => $salesCrm['my_bookings'] ?? 0,
                    'settings'     => $salesCrm['settings'] ?? 0,

                    'whatsapp'     => $salesCrm['whatsapp'] ?? 0,
                    'ai_assistant' => $salesCrm['ai_assistant'] ?? 0,
                    'email'        => $salesCrm['email'] ?? 0,

                    // Project Management
                    'backlog'      => $pm['backlog'] ?? 0,
                    'board'        => $pm['board'] ?? 0,
                    'gantt'        => $pm['gantt'] ?? 0,
                    'pm_audit'     => $pm['pm_audit'] ?? 0,
                    'timetracking' => $pm['timetracking'] ?? 0,
                    'pm_roles'     => $pm['pm_roles'] ?? 0,
                    'pm_system'    => $pm['pm_system'] ?? 0,
                    'pm_users'     => $pm['pm_users'] ?? 0,
                    'projects'     => $pm['projects'] ?? 0,
                    'reports'      => $pm['reports'] ?? 0,
                    'sprints'      => $pm['sprints'] ?? 0,
                ] : null
            ];
        });

        // ✅ Set modified collection back
        $users->setCollection($collection);

        return response()->json($users);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        $user->load(['company:id,name,phone', 'company.businessSuite']);
        return response()->json($this->transform($user));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|min:8',
            'phone'      => 'nullable|string|max:30',
            'role'       => 'required|in:admin,manager,sales_rep,agent',
            'company_id' => 'required|exists:companies,id',
            'status'     => 'required|in:active,inactive',
        ]);

        $data['password'] = Hash::make($data['password']);

        $data['is_active'] = $data['status'] === 'active';
        unset($data['status']);

        $user = User::create($data);
        logActivity(
            'create_user',
            "User {$user->name} added to company {$user->company?->name}",
            $user->company_id
        );

        return response()->json($this->transform($user->load(['company:id,name,phone', 'company.businessSuite'])), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'email'      => "sometimes|email|unique:users,email,{$user->id}",
            'phone'      => 'nullable|string|max:30',
            'role'       => 'sometimes|in:admin,sales_rep',
            'company_id' => 'sometimes|exists:companies,id',
            'status'     => 'sometimes|in:active,inactive',
        ]);

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'active';
            unset($data['status']);
        }

        $user->update($data);

        return response()->json(
            $this->transform($user->fresh()->load(['company:id,name,phone', 'company.businessSuite']))
        );
    }

    public function destroy(User $user): JsonResponse
    {
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true]);
    }
}
