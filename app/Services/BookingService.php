<?php

namespace App\Services;

use Carbon\Carbon;

class BookingService
{
    public function calculateBooking(
        string $startTime,
        string $endTime,
        float  $pricePerHour,
    ): array {
        $start = Carbon::parse($startTime);
        $end   = Carbon::parse($endTime);

        if ($end->lte($start)) {
            throw new \InvalidArgumentException('End time must be after start time.');
        }

        $totalMinutes = $start->diffInMinutes($end);
        $totalHours   = round($totalMinutes / 60, 2);
        $totalPrice   = round($totalHours * $pricePerHour, 2);

        return [
            'total_hours' => $totalHours,
            'total_price' => $totalPrice,
        ];
    }
}
