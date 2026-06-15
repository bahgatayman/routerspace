<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(): View
    {
        $bookings = Booking::with(['owner', 'room.workspace', 'customer'])
            ->latest()
            ->paginate(20);

        return view('admin.bookings.index', compact('bookings'));
    }

    public function show($id): View
    {
        $booking = Booking::with(['owner', 'room.workspace', 'customer'])
            ->findOrFail($id);

        return view('admin.bookings.show', compact('booking'));
    }
}
