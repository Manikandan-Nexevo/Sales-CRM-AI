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
        $query = User::with('company:id,name')->where('role', '!=', 'superadmin');

        // Filter by company — used by Companies > View > Users tab
        if ($companyId = $request->company_id) {
            $query->where('company_id', $companyId);
        }

        if ($s = $request->search) {
            $query->where(
                fn($q) =>
                $q->where('name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhereHas('company', fn($q2) => $q2->where('name', 'like', "%$s%"))
            );
        }

        $users = $query->latest()->paginate($request->per_page ?? 10);
        $users->getCollection()->transform(fn($u) => $this->transform($u));

        return response()->json($users);
    }

    // ── SHOW ──────────────────────────────────────────────────────────────────
    public function show(User $user): JsonResponse
    {
        $user->load('company:id,name');
        return response()->json($this->transform($user));
    }

    // ── STORE ─────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|min:8',
            'phone'      => 'nullable|string|max:30',
            'role'       => 'required|in:admin,manager,sales_rep,agent',
            'company_id' => 'required|exists:companies,id',
            'status'     => 'required|in:active,inactive,suspended',
        ]);

        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log("Created user: {$user->name}");

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
            'status'     => 'sometimes|in:active,inactive,suspended',
        ]);

        // Only hash and update password if a non-empty value was sent
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        // activity()
        //     ->causedBy(auth()->user())
        //     ->performedOn($user)
        //     ->log("Updated user: {$user->name}");

        return response()->json($this->transform($user->fresh()->load('company:id,name')));
    }

    // ── DESTROY ───────────────────────────────────────────────────────────────
    public function destroy(User $user): JsonResponse
    {
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true]);
    }
}
