<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    // ── Allowed permission keys ────────────────────────────────────────────────
    // Extend this list as you add new feature permissions.
    private const VALID_PERMISSIONS = [
        // Companies
        'companies.view', 'companies.create', 'companies.edit', 'companies.delete',
        // Users
        'users.view', 'users.create', 'users.edit', 'users.delete',
        // WhatsApp
        'whatsapp.send', 'whatsapp.templates', 'whatsapp.broadcast', 'whatsapp.settings',
        // AI Assistant
        'ai.assistant', 'ai.call_summary', 'ai.lead_analysis', 'ai.email_gen', 'ai.briefing',
        // Email
        'email.send', 'email.templates', 'email.bulk', 'email.settings',
        // Settings & Admin
        'settings.edit', 'activity.view',
        'roles.view', 'roles.edit',
        'invoices.view', 'transactions.view',
    ];

    /**
     * GET /api/super/roles
     * List all roles with user count and permissions.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')->orderBy('id')->get();
        return response()->json($roles);
    }

    /**
     * GET /api/super/roles/{role}
     * Single role detail (includes users if requested).
     */
    public function show(Role $role): JsonResponse
    {
        $role->loadCount('users');
        return response()->json($role);
    }

    /**
     * POST /api/super/roles
     * Create a new role with permission array.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|unique:roles,name|max:64|regex:/^[a-z0-9_]+$/',
            'label'         => 'required|string|max:100',
            'description'   => 'nullable|string|max:300',
            'permissions'   => 'array',
            'permissions.*' => 'string|in:' . implode(',', self::VALID_PERMISSIONS),
        ], [
            'name.regex'    => 'Role key must be lowercase letters, numbers, and underscores only.',
            'name.unique'   => 'A role with this key already exists.',
            'permissions.*' => 'One or more permissions are invalid.',
        ]);

        $data['permissions'] = array_values(array_unique($data['permissions'] ?? []));

        $role = Role::create($data);
        $role->loadCount('users');

        return response()->json($role, 201);
    }

    /**
     * PUT /api/super/roles/{role}
     * Update label, description and/or permissions.
     * The role `name` (key) is intentionally immutable after creation.
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'label'         => 'sometimes|required|string|max:100',
            'description'   => 'nullable|string|max:300',
            'permissions'   => 'array',
            'permissions.*' => 'string|in:' . implode(',', self::VALID_PERMISSIONS),
        ], [
            'permissions.*' => 'One or more permissions are invalid.',
        ]);

        if (isset($data['permissions'])) {
            $data['permissions'] = array_values(array_unique($data['permissions']));
        }

        $role->update($data);
        $role->loadCount('users');

        return response()->json($role);
    }

    /**
     * DELETE /api/super/roles/{role}
     * Deletes the role — refuses if any users are still assigned to it.
     */
    public function destroy(Role $role): JsonResponse
    {
        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a role that still has users assigned. Re-assign those users first.',
            ], 422);
        }

        $role->delete();
        return response()->json(['success' => true]);
    }

    /**
     * GET /api/super/roles/{role}/users
     * List all users who currently have this role (paginated).
     */
    public function users(Request $request, Role $role): JsonResponse
    {
        $users = User::where('role', $role->name)
            ->with('company:id,name')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    /**
     * PATCH /api/super/roles/{role}/assign
     * Bulk-assign this role to a list of user IDs.
     * Body: { user_ids: [1, 2, 3] }
     */
    public function assign(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'user_ids'   => 'required|array|min:1',
            'user_ids.*' => 'integer|exists:users,id',
        ]);

        User::whereIn('id', $data['user_ids'])->update(['role' => $role->name]);

        return response()->json([
            'message'  => 'Role assigned successfully.',
            'role'     => $role->name,
            'user_ids' => $data['user_ids'],
        ]);
    }
}