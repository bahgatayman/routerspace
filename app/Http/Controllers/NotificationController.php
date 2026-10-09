<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(): Response
    {
        $notifications = Notification::forOwner(TenantContext::id())
            ->latest()
            ->paginate(20);

        $unreadCount = Notification::forOwner(TenantContext::id())->unread()->count();

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications->through(fn (Notification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'level' => $n->levelColor(),
                'icon_path' => $n->iconPath(),
                'read' => $n->isRead(),
                'has_action' => (bool) $n->action_url,
                'ago' => $n->created_at?->diffForHumans(),
            ]),
            'unreadCount' => $unreadCount,
        ]);
    }

    /** Mark a single notification read and redirect to its target (bell click-through). */
    public function open(int $id): RedirectResponse
    {
        $notification = $this->findOwned($id);
        $notification->markAsRead();

        return redirect($notification->action_url ?: '/notifications');
    }

    public function markRead(int $id): RedirectResponse
    {
        $this->findOwned($id)->markAsRead();

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        Notification::forOwner(TenantContext::id())
            ->unread()
            ->update(['read_at' => now()]);

        return back()->with('success', __('app.notif.all_marked_read'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->findOwned($id)->delete();

        return back()->with('success', __('app.notif.deleted'));
    }

    private function findOwned(int $id): Notification
    {
        return Notification::forOwner(TenantContext::id())->findOrFail($id);
    }
}
