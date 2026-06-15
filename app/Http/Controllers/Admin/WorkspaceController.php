<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(): View
    {
        $workspaces = Workspace::with('owner')
            ->withCount('rooms')
            ->latest()
            ->paginate(15);

        return view('admin.workspaces.index', compact('workspaces'));
    }

    public function show(int $id): View
    {
        $workspace = Workspace::with(['owner', 'rooms'])->findOrFail($id);
        $roomsByType = $workspace->rooms->groupBy('type');

        return view('admin.workspaces.show', compact('workspace', 'roomsByType'));
    }
}
