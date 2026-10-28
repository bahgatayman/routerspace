<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A publication action that can't happen: the transition isn't allowed from the
 * workspace's current status, or the workspace isn't ready. The message is
 * already translated and safe to show; `missing` lists unmet readiness checks.
 */
class PublicationException extends RuntimeException
{
    /** @param list<string> $missing */
    public function __construct(string $message, public readonly array $missing = [])
    {
        parent::__construct($message);
    }

    public static function invalidTransition(): self
    {
        return new self(__('app.publication.errors.invalid_transition'));
    }

    /** @param list<string> $missing */
    public static function notReady(array $missing): self
    {
        return new self(__('app.publication.errors.not_ready'), $missing);
    }
}
