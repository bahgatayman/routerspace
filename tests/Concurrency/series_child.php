<?php

/*
 * Child process for RecurringBookingConcurrencyTest (MySQL only): boots the
 * app in its own PHP process and sends ONE real "confirm repeating booking"
 * request (POST /bookings/series) through the HTTP kernel, signed in as the
 * given owner. Prints one JSON line: {"status": …, "location": …, "error": …, "warning": …}.
 *
 * usage: php tests/Concurrency/series_child.php <ownerId> <payload.json>
 * Database settings come from the environment (the parent passes its own).
 */

use App\Models\Owner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

[$script, $ownerId, $payloadFile] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$owner = Owner::findOrFail((int) $ownerId);
Auth::guard('owner')->setUser($owner);
Auth::shouldUse('owner');

$request = Request::create('/bookings/series', 'POST', json_decode(file_get_contents($payloadFile), true));
$response = $kernel->handle($request);
$session = $app->bound('session.store') ? $app['session.store'] : null;
$bag = $session?->get('errors');

echo json_encode([
    'status' => $response->getStatusCode(),
    'location' => $response->headers->get('Location'),
    'error' => $session?->get('error') ?? (is_object($bag) ? implode(' ', $bag->all()) : null),
    'warning' => $session?->get('warning'),
    'success' => $session?->get('success'),
]), PHP_EOL;

$kernel->terminate($request, $response);
