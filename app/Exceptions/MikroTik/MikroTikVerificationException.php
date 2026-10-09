<?php

namespace App\Exceptions\MikroTik;

/** The mutating call itself returned without error, but a read-back afterward found the router's actual state doesn't match what was requested. */
class MikroTikVerificationException extends MikroTikOperationException {}
