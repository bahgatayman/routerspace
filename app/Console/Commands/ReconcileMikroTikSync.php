<?php

namespace App\Console\Commands;

use App\Models\RouterSyncTask;
use App\Services\HotspotSyncService;
use Illuminate\Console\Command;

/**
 * Retries every pending/failed RouterSyncTask against each task's owner's
 * router. A plain synchronous command, not a queued job — the app has no
 * queue worker running anywhere today (confirmed: zero ShouldQueue/dispatch()
 * usages in the whole codebase before this feature), so dispatching to a
 * queue here would add a dependency on infrastructure nothing proves is
 * staffed, exactly the "queue workers that are not running" risk this
 * feature exists to avoid. This command needs only what already exists for
 * notifications:refresh/bookings:complete-expired: the Laravel scheduler
 * (routes/console.php) ticking via the server's own cron — if that cron
 * entry isn't configured in production, neither this command nor those
 * existing ones run automatically; that's a pre-existing deployment fact,
 * not a new one. The owner-facing manual "Retry" button works regardless,
 * since it's a synchronous HTTP action with no scheduler dependency at all.
 *
 * Stops auto-retrying a given task once it's been attempted
 * MAX_AUTO_ATTEMPTS times — beyond that, a persistent failure usually means
 * something needs a human (wrong credentials, router genuinely offline for
 * good), and the manual retry action is always still available.
 */
class ReconcileMikroTikSync extends Command
{
    private const MAX_AUTO_ATTEMPTS = 5;

    protected $signature = 'mikrotik:reconcile';

    protected $description = 'Retry pending/failed MikroTik router-sync tasks (suspend/reactivate/speed changes)';

    public function handle(HotspotSyncService $sync): int
    {
        $tasks = RouterSyncTask::unresolved()
            ->where('attempts', '<', self::MAX_AUTO_ATTEMPTS)
            ->with('owner', 'hotspotUser')
            ->get();

        $succeeded = 0;
        $stillPending = 0;

        foreach ($tasks as $task) {
            if ($sync->retryTask($task)) {
                $succeeded++;
            } else {
                $stillPending++;
            }
        }

        $this->info("Reconciled {$succeeded} task(s); {$stillPending} still pending/failed.");

        return self::SUCCESS;
    }
}
