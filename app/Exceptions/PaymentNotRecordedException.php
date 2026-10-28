<?php

namespace App\Exceptions;

use App\Models\Booking;
use RuntimeException;

/**
 * Thrown inside BookingController::recordPayment()'s transaction when the
 * guarded increment wrote nothing (booking cancelled/no-show, or the amount
 * no longer fits the balance), so the transaction — and the request's
 * idempotency claim with it — rolls back. Carries the booking as re-read
 * inside the transaction, to explain why to the user.
 */
class PaymentNotRecordedException extends RuntimeException
{
    public function __construct(public readonly Booking $booking)
    {
        parent::__construct('Payment not recorded.');
    }
}
