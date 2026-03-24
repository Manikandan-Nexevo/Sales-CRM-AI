<?php

namespace App\Services;

use App\Models\CalendarEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarService
{
    // 🔴 CHECK OVERLAP
    public function isSlotAvailable($userId, $start, $end, $ignoreId = null): bool
    {
        $query = DB::connection('tenant')
            ->table('calendar_events')
            ->where('user_id', $userId);

        // 🔥 IGNORE CURRENT FOLLOW-UP
        if ($ignoreId) {
            $query->where('reference_id', '!=', $ignoreId);
        }

        $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_time', [$start, $end])
                ->orWhereBetween('end_time', [$start, $end])
                ->orWhere(function ($q2) use ($start, $end) {
                    $q2->where('start_time', '<=', $start)
                        ->where('end_time', '>=', $end);
                });
        });

        return !$query->exists();
    }

    // 🔴 FIND NEXT FREE SLOT
    public function getNextAvailableSlot($userId, $duration = 30): Carbon
    {
        $start = now()->setTime(9, 0);

        $events = CalendarEvent::where('user_id', $userId)
            ->whereDate('start_time', today())
            ->orderBy('start_time')
            ->get();

        foreach ($events as $event) {
            if ($start->copy()->addMinutes($duration)->lte($event->start_time)) {
                return $start;
            }
            $start = $event->end_time->copy();
        }

        return $start;
    }

    public function createEvent($userId, $type, $referenceId, $title, $start, $duration = 30)
    {
        $start = Carbon::parse($start);
        $end = $start->copy()->addMinutes($duration);

        if (!$this->isSlotAvailable($userId, $start, $end)) {
            throw new \Exception('Time slot already booked');
        }

        return CalendarEvent::create([
            'user_id' => $userId,
            'type' => $type,
            'reference_id' => $referenceId,
            'title' => $title,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 'scheduled'
        ]);
    }
}
