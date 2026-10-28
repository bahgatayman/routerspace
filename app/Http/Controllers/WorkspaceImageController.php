<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceImage;
use App\Services\ActivityLogger;
use App\Services\ImageUploadService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace photo gallery: list/upload/delete/reorder/set-primary. Scoped like
 * RoomImageController (TenantContext -> owner-scoped Workspace, and every image
 * looked up through that workspace), and gated by workspaces.manage
 * (listing: workspaces.view). Exactly one image is primary while any exist.
 */
class WorkspaceImageController extends Controller
{
    public const MAX_IMAGES_PER_WORKSPACE = 20;

    public const MAX_KILOBYTES = 5120;

    /**
     * New uploads go straight into this directory under a random 40-character name — no
     * workspace id in the path, because these URLs are served by the public API. Images
     * stored earlier under workspace-images/{id}/ keep their recorded path and URL.
     */
    public const DIRECTORY = 'workspace-images';

    public function __construct(
        private ImageUploadService $uploads,
        private ActivityLogger $activityLogger,
    ) {}

    private function getWorkspace(int $workspaceId): Workspace
    {
        return Workspace::where('id', $workspaceId)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();
    }

    private function getImage(Workspace $workspace, int $imageId): WorkspaceImage
    {
        return $workspace->images()->where('id', $imageId)->firstOrFail();
    }

    public function index(int $workspaceId): JsonResponse
    {
        $workspace = $this->getWorkspace($workspaceId);

        return response()->json([
            'success' => true,
            'images' => $workspace->images()->get(),
            'max' => self::MAX_IMAGES_PER_WORKSPACE,
        ]);
    }

    public function store(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);

        $request->validate([
            'images' => ['required', 'array', 'max:'.self::MAX_IMAGES_PER_WORKSPACE],
            // `image` + `mimetypes` inspect the file's content, `mimes` its detected type → extension.
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.self::MAX_KILOBYTES,
                // Belt and braces: decode the actual bytes, whatever the reported/guessed type says.
                function (string $attribute, mixed $file, \Closure $fail) {
                    $info = $file instanceof UploadedFile ? @getimagesize($file->getRealPath()) : false;
                    if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                        $fail(__('validation.image', ['attribute' => $attribute]));
                    }
                },
            ],
        ]);

        $files = $request->file('images');
        if ($workspace->images()->count() + count($files) > self::MAX_IMAGES_PER_WORKSPACE) {
            return back()->with('error', __('app.workspace.workspace_images_limit', ['max' => self::MAX_IMAGES_PER_WORKSPACE]));
        }

        $nextSort = (int) $workspace->images()->max('sort_order');
        $hasPrimary = $workspace->images()->where('is_primary', true)->exists();
        $saved = 0;
        $failed = 0;

        foreach ($files as $file) {
            $stored = null;
            try {
                $stored = $this->uploads->store($file, self::DIRECTORY);

                $workspace->images()->create([
                    'owner_id' => $workspace->owner_id,
                    'path' => $stored['path'],
                    'thumbnail_path' => $stored['thumbnail_path'],
                    'is_primary' => ! $hasPrimary,
                    'sort_order' => ++$nextSort,
                ]);
                $hasPrimary = true;
                $saved++;
            } catch (\Throwable $e) {
                // No row exists for this file, so its files must not stay behind.
                if ($stored) {
                    $this->uploads->delete($stored['path'], $stored['thumbnail_path']);
                }
                report($e);
                $failed++;
            }
        }

        if ($saved > 0) {
            $this->activityLogger->log('workspace.images_uploaded', $workspace, "Added {$saved} photo(s) to workspace {$workspace->name}");
        }

        $redirect = back();
        if ($saved > 0) {
            $redirect = $redirect->with('success', __('app.workspace.workspace_images_uploaded'));
        }
        if ($failed > 0) {
            $redirect = $redirect->with('error', trans_choice('app.workspace.images_partial_failure', $failed, ['count' => $failed]));
        }

        return $redirect;
    }

    public function destroy(Request $request, int $workspaceId, int $imageId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);
        $image = $this->getImage($workspace, $imageId);
        $wasPrimary = $image->is_primary;

        DB::transaction(function () use ($workspace, $image, $wasPrimary) {
            $image->delete();

            // Keep the invariant: one primary while any images are left (none when the gallery is empty).
            if ($wasPrimary) {
                $workspace->images()->first()?->update(['is_primary' => true]);
            }
        });

        // Files go only after the rows are committed (a rollback can't resurrect deleted files).
        $this->uploads->delete($image->path, $image->thumbnail_path);
        $this->activityLogger->log('workspace.image_deleted', $workspace, "Removed a photo from workspace {$workspace->name}");

        return $this->respond($request, __('app.workspace.workspace_image_deleted'));
    }

    public function setPrimary(Request $request, int $workspaceId, int $imageId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);
        $image = $this->getImage($workspace, $imageId);

        DB::transaction(function () use ($workspace, $image) {
            $workspace->images()->where('id', '!=', $image->id)->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });

        return $this->respond($request, __('app.workspace.workspace_image_primary_set'));
    }

    /** Body: { order: [imageId, imageId, ...] } in the desired display order; foreign ids are ignored. */
    public function reorder(Request $request, int $workspaceId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownedIds = $workspace->images()->pluck('id');
        $submitted = collect($data['order'])->map(fn ($id) => (int) $id)->filter(fn ($id) => $ownedIds->contains($id))->unique()->values();

        DB::transaction(function () use ($workspace, $submitted) {
            foreach ($submitted as $index => $imageId) {
                $workspace->images()->where('id', $imageId)->update(['sort_order' => $index + 1]);
            }
        });

        return $this->respond($request, __('app.workspace.workspace_images_reordered'));
    }

    /** JSON for the edit page's fetch-driven controls; a plain redirect otherwise (no-JS fallback). */
    private function respond(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
