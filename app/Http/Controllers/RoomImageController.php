<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Workspace;
use App\Services\ImageUploadService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Room photo gallery: upload/delete/reorder/set-cover. Scoped the same way as
 * RoomController (TenantContext -> owner-scoped Workspace -> workspace-scoped
 * Room) and gated by the same workspaces.manage permission — rooms and their
 * photos are managed together, not a separate permission concept.
 */
class RoomImageController extends Controller
{
    private const MAX_IMAGES_PER_ROOM = 20;

    public function __construct(private ImageUploadService $uploads) {}

    private function getWorkspace(int $workspaceId): Workspace
    {
        return Workspace::where('id', $workspaceId)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();
    }

    private function getRoom(Workspace $workspace, int $roomId): Room
    {
        return Room::where('id', $roomId)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
    }

    public function store(Request $request, int $workspaceId, int $roomId): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);

        $request->validate([
            'images' => ['required', 'array', 'max:'.self::MAX_IMAGES_PER_ROOM],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $existingCount = $room->images()->count();
        if ($existingCount + count($request->file('images')) > self::MAX_IMAGES_PER_ROOM) {
            return back()->with('error', __('app.workspace.room_images_limit', ['max' => self::MAX_IMAGES_PER_ROOM]));
        }

        $nextSort = (int) $room->images()->max('sort_order');
        $hasCover = $room->images()->where('is_cover', true)->exists();
        $failed = 0;

        foreach ($request->file('images') as $file) {
            $stored = null;
            try {
                $stored = $this->uploads->store($file, "room-images/{$room->id}");
                $sort = $nextSort + 1; // only advanced once the row is saved, so a failure can't skip the cover

                $room->images()->create([
                    'owner_id' => $room->owner_id,
                    'path' => $stored['path'],
                    'thumbnail_path' => $stored['thumbnail_path'],
                    'is_cover' => ! $hasCover && $sort === 1,
                    'sort_order' => $sort,
                ]);
                $nextSort = $sort;
                $hasCover = true;
            } catch (\Throwable $e) {
                // No row was saved for this file — don't leave its files behind.
                if ($stored) {
                    $this->uploads->delete($stored['path'], $stored['thumbnail_path']);
                }
                report($e);
                $failed++;
            }
        }

        if ($failed > 0) {
            return back()->with('error', trans_choice('app.workspace.images_partial_failure', $failed, ['count' => $failed]));
        }

        return back()->with('success', __('app.workspace.room_images_uploaded'));
    }

    public function destroy(Request $request, int $workspaceId, int $roomId, int $imageId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);
        $image = $room->images()->where('id', $imageId)->firstOrFail();
        $wasCover = $image->is_cover;

        $image->deleteWithFile();

        // The gallery must always have a cover while it has any images left.
        if ($wasCover) {
            $room->images()->orderBy('sort_order')->first()?->update(['is_cover' => true]);
        }

        return $this->respond($request, __('app.workspace.room_image_deleted'));
    }

    public function setCover(Request $request, int $workspaceId, int $roomId, int $imageId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);
        $image = $room->images()->where('id', $imageId)->firstOrFail();

        DB::transaction(function () use ($room, $image) {
            $room->images()->where('id', '!=', $image->id)->update(['is_cover' => false]);
            $image->update(['is_cover' => true]);
        });

        return $this->respond($request, __('app.workspace.room_image_cover_set'));
    }

    /** Body: { order: [imageId, imageId, ...] } in the desired display order. */
    public function reorder(Request $request, int $workspaceId, int $roomId): Response
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownedIds = $room->images()->pluck('id');
        $submitted = collect($data['order'])->filter(fn ($id) => $ownedIds->contains($id))->values();

        DB::transaction(function () use ($room, $submitted) {
            foreach ($submitted as $index => $imageId) {
                $room->images()->where('id', $imageId)->update(['sort_order' => $index + 1]);
            }
        });

        return $this->respond($request, __('app.workspace.room_images_reordered'));
    }

    /** JSON for the edit page's AJAX-driven delete/cover/reorder controls; a plain redirect otherwise (no-JS fallback). */
    private function respond(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
