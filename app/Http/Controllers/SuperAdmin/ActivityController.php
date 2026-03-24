<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('causer:id,name')->latest();

        if ($s = $request->search) {
            $query->where('description', 'like', "%$s%");
        }

        $logs = $query->paginate($request->per_page ?? 15);
        $logs->getCollection()->transform(fn($log) => [
            'id'          => $log->id,
            'type'        => $log->log_name,
            'description' => $log->description,
            'causer_name' => $log->causer?->name ?? 'System',
            'ip_address'  => $log->properties['ip'] ?? null,
            'created_at'  => $log->created_at->format('d M Y, H:i'),
        ]);

        return response()->json($logs);
    }
}
