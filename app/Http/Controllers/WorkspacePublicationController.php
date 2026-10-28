<?php

namespace App\Http\Controllers;

use App\Exceptions\PublicationException;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\WorkspacePublicationService;
use Illuminate\Http\RedirectResponse;

/**
 * Owner-side publication actions: submit for review / withdraw. Routes sit in
 * an `auth:owner` group (staff can't reach them — only the Owner decides to
 * publish); the workspace is resolved within the signed-in owner's own data.
 */
class WorkspacePublicationController extends Controller
{
    public function __construct(
        private WorkspacePublicationService $publication,
        private ActivityLogger $activityLogger,
    ) {}

    private function workspace(int $id): Workspace
    {
        return Workspace::where('id', $id)->where('owner_id', auth('owner')->id())->firstOrFail();
    }

    public function submit(int $workspace): RedirectResponse
    {
        $ws = $this->workspace($workspace);

        try {
            $this->publication->submit($ws, auth('owner')->user());
        } catch (PublicationException $e) {
            return $this->back($ws)->with('error', $e->getMessage())->with('publication_missing', $e->missing);
        }

        $this->activityLogger->log('workspace.publication_submitted', $ws, "Submitted workspace {$ws->name} for publication");

        return $this->back($ws)->with('success', __('app.publication.flash.submitted'));
    }

    public function withdraw(int $workspace): RedirectResponse
    {
        $ws = $this->workspace($workspace);

        try {
            $this->publication->withdraw($ws, auth('owner')->user());
        } catch (PublicationException $e) {
            return $this->back($ws)->with('error', $e->getMessage());
        }

        $this->activityLogger->log('workspace.publication_withdrawn', $ws, "Withdrew workspace {$ws->name} from review");

        return $this->back($ws)->with('success', __('app.publication.flash.withdrawn'));
    }

    private function back(Workspace $ws): RedirectResponse
    {
        return redirect()->to(route('workspaces.edit', $ws).'#workspace-publication');
    }
}
