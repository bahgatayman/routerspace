<?php

namespace App\Http\Controllers;

use App\Models\RouterSyncTask;
use App\Services\HotspotSyncService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Owner-facing visibility + manual retry for MikroTik sync tasks that
 * couldn't be confirmed automatically (suspend/reactivate/speed changes —
 * see HotspotSyncService). The scheduled `mikrotik:reconcile` command
 * retries these too; this controller is the synchronous, no-infrastructure-
 * required path an owner can use right now instead of waiting for it.
 */
class RouterSyncTaskController extends Controller
{
    public function __construct(private HotspotSyncService $sync) {}

    public function index(): View
    {
        $tasks = RouterSyncTask::where('owner_id', TenantContext::id())
            ->unresolved()
            ->with('hotspotUser', 'speedProfile')
            ->orderByDesc('updated_at')
            ->get();

        return view('router-sync-tasks.index', ['tasks' => $tasks]);
    }

    public function retry(int $id): RedirectResponse
    {
        $task = RouterSyncTask::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        return $this->sync->retryTask($task)
            ? back()->with('success', 'Synced successfully.')
            : back()->with('error', 'Still could not sync — '.($task->fresh()->last_error ?? 'see the error below.'));
    }

    public function retryAll(): RedirectResponse
    {
        $tasks = RouterSyncTask::where('owner_id', TenantContext::id())->unresolved()->get();

        $succeeded = 0;
        foreach ($tasks as $task) {
            if ($this->sync->retryTask($task)) {
                $succeeded++;
            }
        }

        $total = $tasks->count();

        return back()->with($succeeded === $total ? 'success' : 'warning', "Synced {$succeeded} of {$total}.");
    }
}
