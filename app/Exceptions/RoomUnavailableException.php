<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside a booking-creation transaction when the locked room turns
 * out to be marked unavailable (is_available = false) — e.g. the owner
 * switched it off between the form loading and the submit — so nothing is
 * written. The pre-transaction check answers the common case; this one
 * closes the race.
 */
class RoomUnavailableException extends RuntimeException {}
