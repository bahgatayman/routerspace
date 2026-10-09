<?php

namespace App\Exceptions\MikroTik;

/** RouterOS rejected the /login attempt — reachable router, wrong username/password. */
class MikroTikAuthenticationException extends MikroTikException {}
