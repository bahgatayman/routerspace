<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Internal to RecurringBookingService: rolls one occurrence's transaction
 * back when the post-write capacity re-check finds a concurrent conflict.
 */
class OccurrenceConflictException extends RuntimeException {}
