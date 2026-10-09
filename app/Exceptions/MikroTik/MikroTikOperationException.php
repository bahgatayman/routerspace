<?php

namespace App\Exceptions\MikroTik;

/** Authenticated successfully, but a specific RouterOS operation failed (e.g. "!trap" on create/update/delete, or the target user/profile wasn't found). */
class MikroTikOperationException extends MikroTikException {}
