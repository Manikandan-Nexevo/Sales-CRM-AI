<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;

class SetTenantDatabase
{
    public function handle($request, Closure $next)
    {
        $user = auth()->user();

        if (!$user || !$user->company) {
            return $next($request);
        }

        $company = $user->company;

        config([
            'database.connections.tenant.host' => $company->db_host,
            'database.connections.tenant.database' => $company->db_name,
            'database.connections.tenant.username' => $company->db_username,
            'database.connections.tenant.password' => $company->db_password,
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');

        return $next($request);
    }
}
