<?php

/*
 * Child process for RoomUsesConcurrencyTest (MySQL only): boots the app in
 * its own PHP process and sends ONE real "create booking" request through the
 * HTTP kernel (routing, middleware, BookingController::store() and its
 * transaction + Room::lockInventory()), signed in as the given owner.
 * Prints one JSON line: {"status": <http status>, "error": <session error|null>}.
 *
 * usage: php tests/Concurrency/book_child.php <ownerId> <roomId> <memberId> <date> <start> <end>
 * Database settings come from the environment (the parent passes its own).
 */

use App\Models\Owner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

[$script, $ownerId, $roomId, $memberId, $date, $start, $end] = $argv;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Same as the test helper actingAs($owner, 'owner').
$owner = Owner::findOrFail((int) $ownerId);
Auth::guard('owner')->setUser($owner);
Auth::shouldUse('owner');

$request = Request::create('/bookings', 'POST', [
    'room_id' => (int) $roomId,
    'hotspot_user_id' => (int) $memberId,
    'booking_date' => $date,
    'start_time' => $start,
    'end_time' => $end,
]);

$response = $kernel->handle($request);
$session = $app->bound('session.store') ? $app['session.store'] : null;
$error = $session?->get('error');
$bag = $session?->get('errors');
$errors = is_object($bag) ? $bag->all() : collect((array) $bag)->flatten()->filter(fn ($v) => is_string($v))->all();

echo json_encode([
    'status' => $response->getStatusCode(),
    'error' => $error ?? ($errors ? implode(' ', $errors) : null),
    'location' => $response->headers->get('Location'),
]), PHP_EOL;

$kernel->terminate($request, $response);
