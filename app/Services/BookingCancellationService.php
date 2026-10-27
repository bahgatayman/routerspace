<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Cancelling a booking — the one cancellation policy for a single booking
 * (BookingController::updateStatus()) and for occurrences of a recurring
 * series (RecurringBookingService). No new refund rules: package hours go
 * back exactly as before, an invoice's products return to stock, and money
 * already paid stays recorded on the booking.
 */
class BookingCancellationService
{
    public function __construct(
        private HourPackageService $packages,
        private InventoryService $inventory,
    ) {}

    /**
     * Cancel a pending/confirmed booking. Atomic claim, so two racing cancels
     * can't both return the booking's package hours; history rows are kept
     * either way. False when the booking was no longer pending/confirmed.
     */
    public function cancel(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking) {
            $updated = Booking::where('id', $booking->id)
                ->where('owner_id', $booking->owner_id)
                ->whereIn('status', ['pending', 'confirmed'])
                ->update(['status' => 'cancelled']);

            if ($updated) {
                $this->packages->releaseBooking($booking);

                // SalesService::saleForBooking() marks a Sale 'completed'
                // the instant it's created, independent of the booking's
                // own status (the invoice is editable while still
                // pending/confirmed — Booking::invoiceIsEditable()).
                // Without this, cancelling a booking that already has
                // products on its invoice would leave that Sale
                // 'completed' forever: stock never returned, and every
                // revenue/product report keeps counting it as real.
                $sale = $booking->sale;
                if ($sale && $sale->status !== 'cancelled') {
                    foreach ($sale->items as $item) {
                        $this->inventory->giveBack($item);
                    }
                    $sale->update(['status' => 'cancelled']);
                }
            }

            return (bool) $updated;
        });
    }
}
