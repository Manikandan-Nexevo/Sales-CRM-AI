<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Date format helper: d-m-Y H:i:s A
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
     * Transform a company model for list/show responses.
     */
    private function transform(Company $c, bool $includeRelations = false): array
    {
        $data = array_merge(
            $c->toArray(),
            [
                'plan'       => $c->activeSubscription?->plan?->name,
                'created_at' => $this->formatDate($c->created_at),
                'updated_at' => $this->formatDate($c->updated_at),
            ]
        );

        // Never expose raw DB password in responses
        unset($data['db_password']);

        return $data;
    }

    // ── LIST ─────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = Company::withCount('users')->with('activeSubscription.plan');

        if ($s = $request->search) {
            $query->where(fn($q) => $q
                ->where('name',  'like', "%$s%")
                ->orWhere('email', 'like', "%$s%")
            );
        }

        $companies = $query->latest()->paginate($request->per_page ?? 10);

        $companies->getCollection()->transform(fn($c) => $this->transform($c));

        return response()->json($companies);
    }

    // ── SHOW ─────────────────────────────────────────────────────────────────
    public function show(Company $company): JsonResponse
    {
        $company->load(['users', 'subscriptions.plan', 'invoices', 'activeSubscription.plan']);

        $data = $company->toArray();
        $data['created_at'] = $this->formatDate($company->created_at);
        $data['updated_at'] = $this->formatDate($company->updated_at);
        $data['plan']       = $company->activeSubscription?->plan?->name;

        // Format dates for nested relations
        if (!empty($data['users'])) {
            $data['users'] = collect($data['users'])->map(function ($u) {
                $u['created_at']    = $this->formatDate($u['created_at'] ?? null);
                $u['updated_at']    = $this->formatDate($u['updated_at'] ?? null);
                $u['last_login_at'] = $this->formatDate($u['last_login_at'] ?? null);
                unset($u['password']);
                return $u;
            })->toArray();
        }

        // Never expose raw DB password
        unset($data['db_password']);

        return response()->json($data);
    }

    // ── STORE ─────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => 'nullable|string|max:255',
            'email'   => 'nullable|email|unique:companies,email',
            'phone'   => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'status'  => 'nullable|in:active,inactive',

            'user_name'     => 'nullable|string|max:255',
            'user_email'    => 'nullable|email|unique:users,email',
            'user_password' => 'nullable|min:6',

            'db_host'     => 'nullable|string',
            'db_name'     => 'nullable|string',
            'db_username' => 'nullable|string',
            'db_password' => 'nullable|string',
            'db_port'     => 'nullable|integer',
        ]);

        DB::beginTransaction();

        try {
            $company = Company::create([
                'name'        => $data['name'],
                'email'       => $data['email'],
                'phone'       => $data['phone']    ?? null,
                'address'     => $data['address']  ?? null,
                'website'     => $data['website']  ?? null,
                'status'      => $data['status']   ?? 'active',
                'db_host'     => $data['db_host']     ?? null,
                'db_name'     => $data['db_name']     ?? null,
                'db_username' => $data['db_username'] ?? null,
                'db_password' => $data['db_password'] ?? null,
                'db_port'     => $data['db_port']     ?? 3306,
            ]);

            $user = User::create([
                'name'       => $data['user_name'],
                'email'      => $data['user_email'],
                'password'   => Hash::make($data['user_password']),
                'role'       => 'admin',
                'company_id' => $company->id,
            ]);

            DB::commit();

            $companyData = $company->toArray();
            $companyData['created_at'] = $this->formatDate($company->created_at);
            unset($companyData['db_password']);

            $userData = $user->toArray();
            $userData['created_at'] = $this->formatDate($user->created_at);
            unset($userData['password']);

            return response()->json([
                'company' => $companyData,
                'user'    => $userData,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────
    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'email'       => "sometimes|email|unique:companies,email,{$company->id}",
            'phone'       => 'nullable|string|max:30',
            'address'     => 'nullable|string',
            'website'     => 'nullable|string|max:255',
            'status'      => 'sometimes|in:active,inactive',
            'db_host'     => 'nullable|string',
            'db_name'     => 'nullable|string',
            'db_username' => 'nullable|string',
            'db_password' => 'nullable|string',
            'db_port'     => 'nullable|integer',
        ]);

        // Only update db_password if a new one was actually provided
        if (isset($data['db_password']) && empty($data['db_password'])) {
            unset($data['db_password']);
        }

        $company->update($data);

        $result = $company->fresh()->toArray();
        $result['created_at'] = $this->formatDate($company->created_at);
        $result['updated_at'] = $this->formatDate($company->updated_at);
        unset($result['db_password']);

        return response()->json($result);
    }

    // ── DESTROY ───────────────────────────────────────────────────────────────
    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(['success' => true]);
    }
}
