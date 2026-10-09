<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\StaffActivityLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffActivityController extends Controller
{
    public function show(Request $request, Staff $staff): Response
    {
        abort_unless($staff->owner_id === TenantContext::id(), 404);

        $range = $request->get('range', 'month');
        $from = match ($range) {
            'week' => now()->startOfWeek(),
            'all' => null,
            default => now()->startOfMonth(),
        };

        $query = StaffActivityLog::where('staff_id', $staff->id);
        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        // Every number on this page is a live COUNT/GROUP BY against the
        // append-only log — nothing here is a stored, incrementable counter.
        $counts = (clone $query)
            ->selectRaw('action, count(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $activity = (clone $query)
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Staff/Activity', [
            'staff' => ['id' => $staff->id, 'name' => $staff->name],
            'counts' => $counts->map(fn ($total, $action) => [
                'action' => $action,
                'label' => __('app.staff.events.'.$action),
                'total' => (int) $total,
            ])->values(),
            'activity' => $activity->through(fn (StaffActivityLog $entry) => [
                'id' => $entry->id,
                'created' => $entry->created_at->format('M d, Y H:i'),
                'label' => __('app.staff.events.'.$entry->action),
                'description' => $entry->description,
            ]),
            'range' => $range,
        ]);
    }
}
