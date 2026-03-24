<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Setting::pluck('value', 'key'));
    }

    public function update(Request $request): JsonResponse
    {
        foreach ($request->all() as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        activity()->causedBy(auth()->user())->log('Updated system settings');
        return response()->json(['success' => true]);
    }
}
