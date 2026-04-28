<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

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

    private function transform(Company $c, bool $includeRelations = false): array
    {
        $userCount = DB::connection('mysql')
            ->table('users')
            ->where('company_id', $c->id)
            ->count();

        $data = array_merge(
            $c->toArray(),
            [
                'plan'       => $c->activeSubscription?->plan?->name,
                'users'      => $userCount,
                'created_at' => $this->formatDate($c->created_at),
                'updated_at' => $this->formatDate($c->updated_at),
            ]
        );

        unset($data['db_password']);

        return $data;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Company::with('activeSubscription.plan');

        if ($s = $request->search) {
            $query->where(
                fn($q) => $q
                    ->where('name',  'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
            );
        }

        $companies = $query->latest()->paginate($request->per_page ?? 10);

        $companies->getCollection()->transform(fn($c) => $this->transform($c));

        return response()->json($companies);
    }

    public function show(Company $company): JsonResponse
    {
        $company->load(['users', 'subscriptions.plan', 'activeSubscription.plan']);

        $data = $company->toArray();
        $data['created_at'] = $this->formatDate($company->created_at);
        $data['updated_at'] = $this->formatDate($company->updated_at);
        $data['plan']       = $company->activeSubscription?->plan?->name;

        if (!empty($data['users'])) {
            $data['users'] = collect($data['users'])->map(function ($u) {
                $u['created_at']    = $this->formatDate($u['created_at'] ?? null);
                $u['updated_at']    = $this->formatDate($u['updated_at'] ?? null);
                $u['last_login_at'] = $this->formatDate($u['last_login_at'] ?? null);
                unset($u['password']);
                return $u;
            })->toArray();
        }

        unset($data['db_password']);

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:companies,email',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',

            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|unique:users,email',
            'user_password' => 'required|min:6',
        ]);

        try {
            $company = Company::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'website' => $data['website'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);
            ActivityLog::create([
                'user_id' => Auth::id(),
                'company_id' => $company->id,
                'action' => 'create_company',
                'description' => "Company {$company->name} created",
            ]);

            $dbName = 'tenant_' . $company->id;
            DB::statement("CREATE DATABASE `$dbName`");

            $company->update([
                'db_host' => '127.0.0.1',
                'db_name' => $dbName,
                'db_username' => 'root',
                'db_password' => null,
                'db_port' => 3306,
            ]);

            config([
                'database.connections.tenant' => [
                    'driver' => 'mysql',
                    'host' => '127.0.0.1',
                    'database' => $dbName,
                    'username' => 'root',
                    'password' => '',
                    'port' => 3306,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ]
            ]);

            DB::purge('tenant');
            DB::reconnect('tenant');

            $sql = file_get_contents(database_path('sql/tenant_schema.sql'));
            DB::connection('tenant')->unprepared($sql);

            $user = User::create([
                'name' => $data['user_name'],
                'email' => $data['user_email'],
                'password' => Hash::make($data['user_password']),
                'role' => 'admin',
                'company_id' => $company->id,
            ]);

            return response()->json([
                'company' => $company,
                'user' => $user
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'email'       => "sometimes|email|unique:companies,email,{$company->id}",
            'phone'       => 'nullable|string|max:30',
            'address'     => 'nullable|string',
            'website'     => 'nullable|string|max:255',
            'status'      => 'nullable|in:active,inactive',

            'db_host'     => 'nullable|string',
            'db_name'     => 'nullable|string',
            'db_username' => 'nullable|string',
            'db_password' => 'nullable|string',
            'db_port'     => 'nullable|integer',

            'db_mode'     => 'nullable|in:auto,external',
        ]);

        $dbMode = $request->input('db_mode');

        if ($dbMode === 'external') {
            try {
                config([
                    'database.connections.temp' => [
                        'driver'   => 'mysql',
                        'host'     => $data['db_host'] ?? null,
                        'database' => $data['db_name'] ?? null,
                        'username' => $data['db_username'] ?? null,
                        'password' => $data['db_password'] ?? null,
                        'port'     => $data['db_port'] ?? 3306,
                    ]
                ]);

                DB::connection('temp')->getPdo();
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'External DB connection failed'
                ], 422);
            }
        }

        if (isset($data['db_password']) && empty($data['db_password'])) {
            unset($data['db_password']);
        }

        if ($request->has('status')) {
            $company->status = $request->input('status');
        }

        $company->fill($data);
        $oldStatus = $company->status;
        $newStatus = $request->input('status');
        $company->save();

        if ($newStatus && $oldStatus !== $newStatus) {
            ActivityLog::create([
                'user_id' => Auth::id(),
                'company_id' => $company->id,
                'action' => 'company_status',
                'description' => "Company {$company->name} changed from {$oldStatus} to {$newStatus}",
            ]);
        } else {
            ActivityLog::create([
                'user_id' => Auth::id(),
                'company_id' => $company->id,
                'action' => 'update_company',
                'description' => "Company {$company->name} updated",
            ]);
        }

        $company = $company->fresh();

        $result = $company->toArray();
        $result['created_at'] = $this->formatDate($company->created_at);
        $result['updated_at'] = $this->formatDate($company->updated_at);

        unset($result['db_password']);

        return response()->json($result);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        ActivityLog::create([
            'user_id' => Auth::id(),
            'company_id' => $company->id,
            'action' => 'delete',
            'description' => "Company {$company->name} deleted"
        ]);
        return response()->json(['success' => true]);
    }
}
