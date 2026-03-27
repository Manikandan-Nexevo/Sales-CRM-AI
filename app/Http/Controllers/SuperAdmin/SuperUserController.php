<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperUserController extends Controller
{
    /**
     * Format date to d-m-Y h:i:s A
     */
    private function formatDate(?string $date): ?string
    {
        if (!$date) return null;
        try {
            return \Carbon\Carbon::parse($date)->format('d-m-Y h:i:s A');
        } catch (\Exception $e) {
            return $date;
        }
    }

    /**
     * Transform user for API response.
     * - Formats dates
     * - Appends company_name
     * - Strips password
     */
    private function transform(User $u): array
    {
        $data = array_merge(
            $u->toArray(),
            [
                'company_name'  => $u->company?->name,
                'created_at'    => $this->formatDate($u->created_at),
                'updated_at'    => $this->formatDate($u->updated_at),
                'last_login_at' => $this->formatDate($u->last_login_at ?? null),
            ]
        );

        unset($data['password']);

        return $data;
    }

    // ── LIST ──────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = User::with('company:id,name')
            ->where('role', '!=', 'superadmin');

        // ── FILTERS ─────────────────────────────

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

        // ── PAGINATION ─────────────────────────
        /** @var \Illuminate\Pagination\LengthAwarePaginator $users */
        $users = $query->latest()->paginate($request->per_page ?? 10);

        $collection = $users->getCollection()->map(fn($u) => $this->transform($u));

        $users->setCollection($collection);

        return response()->json($users);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        $user->load('company:id,name');
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

        // ✅ CONVERT STATUS → BOOLEAN
        $data['is_active'] = $data['status'] === 'active';
        unset($data['status']);

        $user = User::create($data);
        logActivity(
            'create_user',
            "User {$user->name} added to company {$user->company?->name}",
            $user->company_id
        );

        return response()->json($this->transform($user->load('company:id,name')), 201);
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────
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

        // ✅ HANDLE PASSWORD
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // ✅ CONVERT STATUS → BOOLEAN
        if (isset($data['status'])) {
            $data['is_active'] = $data['status'] === 'active';
            unset($data['status']);
        }

        $user->update($data);

        return response()->json(
            $this->transform($user->fresh()->load('company:id,name'))
        );
    }

    // ── DESTROY ───────────────────────────────────────────────────────────────
    public function destroy(User $user): JsonResponse
    {
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true]);
    }
}
