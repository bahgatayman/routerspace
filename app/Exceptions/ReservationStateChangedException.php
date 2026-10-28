<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown inside SharedSessionController::close() when the session's
 * reservation is no longer the 'checked_in' booking it was at check-in (or a
 * payment changed it mid-close), so the whole close — including the session's
 * own open → closed claim — rolls back instead of finalizing a stale row.
 */
class ReservationStateChangedException extends RuntimeException {}
