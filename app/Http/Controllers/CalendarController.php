<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $start = $request->start;
        $end = $request->end;

        $events = CalendarEvent::where('user_id', $user->id)
            ->whereBetween('start_time', [$start, $end])
            ->get();

        // 🔥 IMPORTANT: return only start & end
        return $events->map(function ($e) {
            return [
                'start' => $e->start_time,
                'end' => $e->end_time,
            ];
        });
    }
}
