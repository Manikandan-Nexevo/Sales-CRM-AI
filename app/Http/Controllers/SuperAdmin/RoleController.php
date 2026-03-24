<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Role::withCount('users')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|unique:roles,name',
            'label'         => 'required|string',
            'description'   => 'nullable|string',
            'permissions'   => 'array',
            'permissions.*' => 'string',
        ]);
        return response()->json(Role::create($data), 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'label'       => 'sometimes|string',
            'description' => 'nullable|string',
            'permissions' => 'array',
        ]);
        $role->update($data);
        return response()->json($role);
    }

    public function destroy(Role $role): JsonResponse
    {
        if ($role->users()->exists()) {
            return response()->json(['message' => 'Cannot delete role with assigned users.'], 422);
        }
        $role->delete();
        return response()->json(['success' => true]);
    }
}
