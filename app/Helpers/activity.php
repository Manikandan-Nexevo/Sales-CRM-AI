<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

if (!function_exists('logActivity')) {
    function logActivity($action, $description, $companyId = null)
    {
        $companyId = $companyId ?? (Auth::check() ? Auth::user()->company_id : null);
        ActivityLog::create([
            'user_id' => Auth::id(),
            'company_id' => $companyId,
            'action' => $action,
            'description' => $description,
            'created_at' => now(),
        ]);
    }
}
