<?php

namespace App\Exceptions\MikroTik;

/** The socket couldn't be opened, or a read/write on it failed — a network/host/port problem, not a credentials problem. */
class MikroTikConnectionException extends MikroTikException {}
