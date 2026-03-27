<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

if (!function_exists('logActivity')) {
    function logActivity($action, $description, $companyId = null)
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'company_id' => $companyId,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
