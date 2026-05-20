<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CallLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // All valid permission keys (mirrors the frontend constants)
    // ─────────────────────────────────────────────────────────────────────────
    private const VALID_PERMISSIONS = [
        // Sidebar keys
        'sidebar.contacts', 'sidebar.calls', 'sidebar.followups',
        'sidebar.team', 'sidebar.availability', 'sidebar.bookings', 'sidebar.settings',

        // Contacts
        'contacts.create', 'contacts.view', 'contacts.edit',
        'contacts.delete', 'contacts.assign', 'contacts.export',

        // Calls
        'calls.create', 'calls.view', 'calls.export',

        // Follow-ups
        'followups.create',

        // Team
        'team.manage',

        // Settings
        'settings.profile', 'settings.account', 'settings.export',
        'settings.integrations', 'settings.google_calendar',

        // WhatsApp
        'whatsapp.send', 'whatsapp.templates', 'whatsapp.broadcast', 'whatsapp.settings',

        // AI
        'ai.assistant', 'ai.call_summary', 'ai.lead_analysis', 'ai.email_gen', 'ai.briefing',

        // Email
        'email.send', 'email.templates', 'email.bulk', 'email.settings',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/users  —  list all team members for this company
    // ─────────────────────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $companyId = $user->company_id;

        if ($user->normalized_role !== 'admin' && $user->normalized_role !== 'manager') {
            // Sales reps and others get a simple list for dropdowns etc.
            $users = User::where('company_id', $companyId)
                ->where('is_active', true)
                ->select('id', 'name', 'role')
                ->orderBy('name')
                ->get();
            return response()->json($users);
        }

        // Admins and managers get the full list with stats
        $users = User::where('company_id', $companyId)
            ->withCount([
                'callLogs as today_calls' => fn($q) => $q->whereDate('created_at', today()),
            ])
            ->withCount('contacts as total_contacts')
            ->get()
            ->map(fn(User $u) => $this->formatUser($u));

        return response()->json($users);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/users  —  create a new team member
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        // Only admins / managers may create team members
        if (auth()->user()->normalized_role !== 'admin' && auth()->user()->normalized_role !== 'manager') {
            abort(403, 'This action is unauthorized.');
        }

        $roleInput = $request->input('role');
        $normalizedRole = null;
        if (is_array($roleInput)) {
            $normalizedRole = $roleInput['sales_crm'] ?? $roleInput['project_management_tool'] ?? reset($roleInput);
        } elseif (is_string($roleInput) && strpos($roleInput, '{') === 0) {
            $decoded = json_decode($roleInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $normalizedRole = $decoded['sales_crm'] ?? $decoded['project_management_tool'] ?? reset($decoded);
            }
        } else {
            $normalizedRole = $roleInput;
        }

        if ($normalizedRole) {
            $request->merge(['role' => $normalizedRole]);
        }

        $data = $request->validate([
            'name'         => ['required', 'string', 'min:2', 'max:191'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'phone'        => ['nullable', 'string', 'max:30'],
            'password'     => ['required', 'string', 'min:8'],
            'role'         => ['required', Rule::in(['admin', 'manager', 'sales_rep'])],
            'permissions'  => ['nullable', 'array'],
            'permissions.*'=> ['string', Rule::in(self::VALID_PERMISSIONS)],
            'target_calls_daily'   => ['nullable', 'integer', 'min:0'],
            'target_leads_monthly' => ['nullable', 'integer', 'min:0'],
        ]);

        $user = User::create([
            'name'                 => $data['name'],
            'email'                => strtolower($data['email']),
            'phone'                => $data['phone'] ?? null,
            'password'             => Hash::make($data['password']),
            'role'                 => $roleInput ?? $data['role'],
            'permissions'          => array_values(array_unique($data['permissions'] ?? [])),
            'company_id'           => auth()->user()->company_id,
            'is_active'            => true,
            'target_calls_daily'   => $data['target_calls_daily'] ?? 50,
            'target_leads_monthly' => $data['target_leads_monthly'] ?? 10,
        ]);

        // Create business suite entry for the created user
        \App\Models\Business_suite::create([
            'user_id' => $user->id,
            'sales_crm' => 1,
            'project_managment_tool' => 0,
            'status' => 'active',
        ]);

        logActivity('create_team_member', "Team member {$user->name} has been created");

        return response()->json([
            'success' => true,
            'message' => 'Team member created successfully.',
            'user'    => $this->formatUser($user),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/users/{user}  —  single user detail
    // ─────────────────────────────────────────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        $this->ensureSameCompany($user);
        $user->loadCount(['callLogs', 'contacts']);

        return response()->json($this->formatUser($user));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/users/{user}  —  update basic info
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureSameCompany($user);
        if (auth()->user()->normalized_role !== 'admin' && auth()->user()->normalized_role !== 'manager') {
            abort(403, 'This action is unauthorized.');
        }

        $roleInput = $request->input('role');
        $normalizedRole = null;
        if (is_array($roleInput)) {
            $normalizedRole = $roleInput['sales_crm'] ?? $roleInput['project_management_tool'] ?? reset($roleInput);
        } elseif (is_string($roleInput) && strpos($roleInput, '{') === 0) {
            $decoded = json_decode($roleInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $normalizedRole = $decoded['sales_crm'] ?? $decoded['project_management_tool'] ?? reset($decoded);
            }
        } else {
            $normalizedRole = $roleInput;
        }

        if ($normalizedRole) {
            $request->merge(['role' => $normalizedRole]);
        }

        $data = $request->validate([
            'name'  => ['sometimes', 'string', 'min:2', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role'  => ['sometimes', Rule::in(['admin', 'manager', 'sales_rep'])],
            'target_calls_daily'   => ['sometimes', 'integer', 'min:0'],
            'target_leads_monthly' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['role'])) {
            $data['role'] = $roleInput ?? $data['role'];
        }

        // Never allow email / password update through this endpoint
        $user->update($data);

        logActivity('update_team_member', "Team member {$user->name} has been updated");

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'user'    => $this->formatUser($user->fresh()),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/users/{user}  —  soft-delete (deactivate)
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(User $user): JsonResponse
    {
        $this->ensureSameCompany($user);
        if (auth()->user()->normalized_role !== 'admin' && auth()->user()->normalized_role !== 'manager') {
            abort(403, 'This action is unauthorized.');
        }

        $user->update(['is_active' => false]);

        logActivity('deactivate_team_member', "Team member {$user->name} has been deactivated");

        return response()->json(['success' => true, 'message' => 'User deactivated.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/users/{user}/permissions  —  replace permissions
    // ─────────────────────────────────────────────────────────────────────────
    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $this->ensureSameCompany($user);
        if (auth()->user()->normalized_role !== 'admin' && auth()->user()->normalized_role !== 'manager') {
            abort(403, 'This action is unauthorized.');
        }

        $data = $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', Rule::in(self::VALID_PERMISSIONS)],
        ]);

        $user->syncPermissions($data['permissions']);

        return response()->json([
            'success'     => true,
            'message'     => 'Permissions updated.',
            'permissions' => $user->permissions,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/users/{user}/performance
    // ─────────────────────────────────────────────────────────────────────────
    public function performance(User $user): JsonResponse
    {
        $this->ensureSameCompany($user);

        $base = CallLog::where('user_id', $user->id);

        return response()->json([
            'user'            => $this->formatUser($user),
            'today_calls'     => (clone $base)->whereDate('created_at', today())->count(),
            'week_calls'      => (clone $base)->whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'month_calls'     => (clone $base)->whereMonth('created_at', now()->month)->count(),
            'conversion_rate' => $this->calcConversion($user->id),
            'avg_duration'    => (int) round((clone $base)->avg('duration') ?? 0),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/users/simple  —  lightweight list for dropdowns
    // ─────────────────────────────────────────────────────────────────────────


    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function formatUser(User $user): array
    {
        return [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'phone'                 => $user->phone,
            'role'                  => $user->role,
            'permissions'           => $user->permissions ?? [],
            'sidebar_items'         => $user->sidebarItems(),
            'is_active'             => $user->is_active,
            'company_id'            => $user->company_id,
            'target_calls_daily'    => $user->target_calls_daily,
            'target_leads_monthly'  => $user->target_leads_monthly,
            'today_calls'           => $user->today_calls ?? null,
            'total_contacts'        => $user->total_contacts ?? null,
            'created_at'            => $user->created_at,
        ];
    }

    private function calcConversion(int $userId): float
    {
        $total     = CallLog::where('user_id', $userId)->count();
        $connected = CallLog::where('user_id', $userId)->where('status', 'connected')->count();
        return $total > 0 ? round(($connected / $total) * 100, 1) : 0.0;
    }

    private function ensureSameCompany(User $user): void
    {
        if ($user->company_id !== auth()->user()->company_id) {
            abort(403, 'Access denied.');
        }
    }
}