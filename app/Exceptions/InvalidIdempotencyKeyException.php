<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request carried an idempotency key that isn't 1–100 characters of
 * [A-Za-z0-9_-]. Rejected before anything is written; Panel forms always
 * generate valid keys, so this only happens to hand-crafted requests.
 */
class InvalidIdempotencyKeyException extends RuntimeException {}
