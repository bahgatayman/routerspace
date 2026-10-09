<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Owner;
use App\Services\AdminAuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** Compose form + recent broadcast history. */
    public function index(): Response
    {
        $owners = Owner::orderBy('business_name')->get(['id', 'business_name', 'name', 'is_active']);

        // Group each broadcast (shared reference) into a single history row.
        $recent = Notification::where('type', 'admin_message')
            ->latest()
            ->limit(300)
            ->get()
            ->groupBy('reference')
            ->map(function ($group) {
                $first = $group->first();

                return (object) [
                    'title' => $first->title,
                    'body' => $first->body,
                    'level' => $first->level,
                    'sent_at' => $first->created_at,
                    'recipients' => $group->count(),
                    'read_count' => $group->whereNotNull('read_at')->count(),
                ];
            })
            ->sortByDesc('sent_at')
            ->take(15)
            ->values();

        return Inertia::render('Admin/Notifications/Index', [
            'owners' => $owners->map(fn (Owner $o) => ['id' => $o->id, 'name' => $o->business_name ?: $o->name, 'is_active' => (bool) $o->is_active]),
            'recent' => $recent->map(fn ($r, $i) => [
                'key' => $i,
                'title' => $r->title,
                'body' => $r->body,
                'level' => $r->level,
                'sent' => $r->sent_at->format('M j, H:i'),
                'recipients' => $r->recipients,
                'read_count' => $r->read_count,
            ]),
        ]);
    }

    /** Send a notification to all, active-only, or selected owners. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:1000'],
            'level' => ['required', 'in:info,success,warning,danger'],
            'action_url' => ['nullable', 'string', 'max:255'],
            'target' => ['required', 'in:all,active,selected'],
            'owner_ids' => ['required_if:target,selected', 'array'],
            'owner_ids.*' => ['integer', 'exists:owners,id'],
        ]);

        $owners = Owner::query()
            ->when($validated['target'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($validated['target'] === 'selected', fn ($q) => $q->whereIn('id', $validated['owner_ids']))
            ->get();

        if ($owners->isEmpty()) {
            return back()->withInput()->with('error', __('app.admin_notif.no_recipients'));
        }

        // One shared reference per broadcast so history can group recipients together.
        $batch = 'admin:'.now()->format('YmdHis').':'.Str::random(6);

        foreach ($owners as $owner) {
            $this->notifications->notify($owner, [
                'type' => 'admin_message',
                'level' => $validated['level'],
                'title' => $validated['title'],
                'body' => $validated['body'] ?? null,
                'action_url' => ($validated['action_url'] ?? null) ?: null,
                'reference' => $batch,
            ]);
        }

        app(AdminAuditLogger::class)->log('notification.broadcast', $owners->count() === 1 ? $owners->first() : null,
            "Sent notification '{$validated['title']}' to {$owners->count()} business(es)",
            ['target' => $validated['target'], 'recipients' => $owners->count(), 'reference' => $batch]);

        return back()->with('success', __('app.admin_notif.sent', ['count' => $owners->count()]));
    }
}
