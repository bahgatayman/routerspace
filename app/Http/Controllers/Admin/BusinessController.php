<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Owner;
use App\Models\Workspace;
use App\Services\Admin\BusinessOverviewService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → one business (Owner = tenant) in one place. Every query is
 * keyed to this owner; a `?workspace=` location filter is only accepted for
 * a location that belongs to it (another owner's id → 404).
 */
class BusinessController extends Controller
{
    public function show(Request $request, int $owner, BusinessOverviewService $overview): View
    {
        $owner = Owner::with(['plan', 'workspaces' => fn ($q) => $q->orderBy('name')])->findOrFail($owner);
        $workspace = $this->location($request, $owner);

        return view('admin.business.overview', [
            'owner' => $owner,
            'workspace' => $workspace,
            'summary' => $overview->summary($owner, $workspace),
            'health' => $overview->health($owner),
            'activity' => $overview->recentActivity($owner),
        ]);
    }

    /** Super Admin actions taken on this business (audit trail). */
    public function audit(int $owner): View
    {
        $owner = Owner::with('workspaces')->findOrFail($owner);

        return view('admin.business.audit', [
            'owner' => $owner,
            'workspace' => null,
            'logs' => AdminAuditLog::where('owner_id', $owner->id)->latest('created_at')->paginate(25),
        ]);
    }

    /** The selected location, only if it belongs to this owner. */
    private function location(Request $request, Owner $owner): ?Workspace
    {
        $id = $request->integer('workspace');

        return $id ? Workspace::where('owner_id', $owner->id)->findOrFail($id) : null;
    }
}
