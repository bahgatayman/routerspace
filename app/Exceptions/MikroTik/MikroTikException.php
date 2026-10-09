<?php

namespace App\Exceptions\MikroTik;

use Exception;

/**
 * Base type for every MikroTikService failure. Existing call sites that
 * still `catch (\Exception $e)` keep working unchanged — these are a
 * refinement for callers that want to tell failure kinds apart (e.g. to
 * show "router unreachable" vs "wrong credentials" instead of one generic
 * message), not a breaking change to error handling.
 */
class MikroTikException extends Exception {}
