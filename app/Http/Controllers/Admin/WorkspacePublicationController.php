<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PublicationException;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\WorkspacePublicationService;
use App\Services\WorkspaceReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → workspace publication review. Behind auth:admin (every admin
 * is a Super Admin in this app — there are no admin roles). Readiness shown
 * here is computed live, and is re-checked again by the service on approve.
 */
class WorkspacePublicationController extends Controller
{
    public function __construct(
        private WorkspacePublicationService $publication,
        private WorkspaceReadinessService $readiness,
    ) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), Workspace::PUBLICATION_STATUSES, true) && $request->query('status') !== Workspace::DRAFT
            ? $request->query('status')
            : Workspace::PENDING_REVIEW;

        $workspaces = Workspace::query()
            ->where('publication_status', $status)
            ->with(['owner:id,name,business_name,email,is_active,subscription_expires_at', 'images', 'rooms'])
            ->orderBy($status === Workspace::PENDING_REVIEW ? 'updated_at' : 'id', $status === Workspace::PENDING_REVIEW ? 'asc' : 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('admin.publications.index', [
            'workspaces' => $workspaces,
            'status' => $status,
            'counts' => Workspace::query()->where('publication_status', '!=', Workspace::DRAFT)
                ->selectRaw('publication_status, COUNT(*) as n')->groupBy('publication_status')->pluck('n', 'publication_status'),
            'readiness' => $workspaces->getCollection()->mapWithKeys(fn ($ws) => [$ws->id => $this->readiness->check($ws)]),
        ]);
    }

    public function show(int $workspace): View
    {
        $ws = Workspace::with(['owner', 'images', 'rooms', 'publicationReviews'])->findOrFail($workspace);

        return view('admin.publications.show', [
            'workspace' => $ws,
            'readiness' => $this->readiness->check($ws),
        ]);
    }

    public function approve(int $workspace): RedirectResponse
    {
        return $this->act($workspace, fn ($ws) => $this->publication->approve($ws, auth('admin')->user()), 'approved');
    }

    public function reject(Request $request, int $workspace): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], $this->reasonMessages());

        return $this->act($workspace, fn ($ws) => $this->publication->reject($ws, auth('admin')->user(), $data['reason']), 'rejected');
    }

    public function unpublish(Request $request, int $workspace): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], $this->reasonMessages());

        return $this->act($workspace, fn ($ws) => $this->publication->unpublish($ws, auth('admin')->user(), $data['reason']), 'unpublished');
    }

    private function act(int $workspace, callable $action, string $done): RedirectResponse
    {
        $ws = Workspace::findOrFail($workspace);

        try {
            $action($ws);
        } catch (PublicationException $e) {
            $missing = collect($e->missing)->map(fn ($k) => __('app.publication.checks.'.$k.'.label'))->implode(', ');

            return redirect()->route('admin.publications.show', $ws)
                ->with('error', $e->getMessage().($missing !== '' ? ' — '.$missing : ''));
        }

        return redirect()->route('admin.publications.show', $ws)->with('success', __('app.publication.flash.'.$done));
    }

    private function reasonMessages(): array
    {
        return [
            'reason.required' => __('app.publication.errors.reason_required'),
            'reason.min' => __('app.publication.errors.reason_short'),
            'reason.max' => __('app.publication.errors.reason_long'),
        ];
    }
}
