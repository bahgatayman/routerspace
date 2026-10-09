<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesTimeSlots;
use App\Models\WorkingHour;
use App\Services\ActivityLogger;
use App\Services\HotspotSyncService;
use App\Support\TenantContext;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use GeneratesTimeSlots;

    /** Display order for the working-hours form: Saturday first. Storage stays Carbon's native 0=Sun..6=Sat. */
    private const DAY_DISPLAY_ORDER = [6, 0, 1, 2, 3, 4, 5];

    /** Carbon's native 0=Sun..6=Sat, mapped to the app.day.* translation keys. */
    private const DAY_NAME_KEYS = [
        0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday',
        4 => 'thursday', 5 => 'friday', 6 => 'saturday',
    ];

    public function __construct(private HotspotSyncService $sync, private ActivityLogger $activityLogger) {}

    public function index(): Response
    {
        $owner = TenantContext::user();

        $existingHours = $owner->workingHours()->get()->keyBy('day_of_week');

        $workingHours = collect(self::DAY_DISPLAY_ORDER)->map(function (int $dayOfWeek) use ($existingHours) {
            $row = $existingHours[$dayOfWeek] ?? null;

            return [
                'day_of_week' => $dayOfWeek,
                'day_name' => __('app.day.'.self::DAY_NAME_KEYS[$dayOfWeek]),
                'is_open' => $row->is_open ?? false,
                'open_time' => $row?->open_time ? substr($row->open_time, 0, 5) : null,
                'close_time' => $row?->close_time ? substr($row->close_time, 0, 5) : null,
            ];
        });

        // The router password is never sent to the page: a blank field keeps the stored one.
        return Inertia::render('Settings/Index', [
            'features' => [
                'hotspot' => $owner->hasFeature('hotspot'),
                'workspace' => $owner->hasFeature('workspace'),
                'booking' => $owner->hasFeature('booking'),
            ],
            'router' => [
                'mikrotik_host' => $owner->mikrotik_host,
                'mikrotik_port' => $owner->mikrotik_port ?? 8728,
                'mikrotik_username' => $owner->mikrotik_username,
                'configured' => $owner->hasRouterConfigured(),
            ],
            'workingHours' => $workingHours->values(),
            'workingHoursTimeSlots' => collect($this->generateTimeSlots('00:00', '23:30'))
                ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values(),
            'hasConfiguredWorkingHours' => $existingHours->isNotEmpty(),
        ]);
    }

    /**
     * Save/change the owner's MikroTik router credentials. This is the post-signup
     * write-path (registration no longer collects them). Gated by feature:hotspot.
     * A blank password keeps the stored one.
     */
    public function update(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'mikrotik_host' => 'required|string',
            'mikrotik_port' => 'required|integer|min:1|max:65535',
            'mikrotik_username' => 'required|string',
            'mikrotik_password' => 'nullable|string',
        ]);

        $owner->update([
            'mikrotik_host' => $validated['mikrotik_host'],
            'mikrotik_port' => $validated['mikrotik_port'],
            'mikrotik_username' => $validated['mikrotik_username'],
            'mikrotik_password' => filled($validated['mikrotik_password'] ?? null)
                ? $validated['mikrotik_password']
                : $owner->mikrotik_password,
        ]);

        $this->activityLogger->log('settings.updated', $owner, 'Updated router settings');

        return back()->with('success', 'Router settings saved successfully.');
    }

    public function testConnection(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();

        try {
            $this->sync->testConnection($owner);

            return back()->with('success', 'Connected to MikroTik successfully');
        } catch (Exception $e) {
            return back()->with('error', "Connection failed: {$e->getMessage()}");
        }
    }

    /**
     * Save the owner's full weekly schedule. Always writes all 7 days
     * atomically (delete-then-insert inside one transaction) so an owner is
     * only ever at 0 rows (unconfigured/unrestricted) or 7 rows (fully
     * configured) — never a partial state for BusinessHoursService to
     * reason about.
     */
    public function updateWorkingHours(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'hours' => 'required|array',
            'hours.*.is_open' => 'required|boolean',
            'hours.*.open_time' => 'nullable|date_format:H:i',
            'hours.*.close_time' => 'nullable|date_format:H:i',
        ]);

        $submitted = $validated['hours'];
        ksort($submitted);

        if (array_map('intval', array_keys($submitted)) !== range(0, 6)) {
            return back()->with('error', __('app.settings.working_hours.invalid_days'))->withInput();
        }

        foreach ($submitted as $day) {
            if (! ($day['is_open'] ?? false)) {
                continue;
            }

            if (blank($day['open_time'] ?? null) || blank($day['close_time'] ?? null)) {
                return back()->with('error', __('app.settings.working_hours.times_required'))->withInput();
            }

            if ($day['open_time'] === $day['close_time']) {
                return back()->with('error', __('app.settings.working_hours.times_equal'))->withInput();
            }
        }

        DB::transaction(function () use ($owner, $submitted) {
            $owner->workingHours()->delete();

            foreach ($submitted as $dayOfWeek => $day) {
                $isOpen = (bool) ($day['is_open'] ?? false);

                WorkingHour::create([
                    'owner_id' => $owner->id,
                    'day_of_week' => (int) $dayOfWeek,
                    'is_open' => $isOpen,
                    'open_time' => $isOpen ? $day['open_time'] : null,
                    'close_time' => $isOpen ? $day['close_time'] : null,
                ]);
            }
        });

        $this->activityLogger->log('settings.updated', $owner, 'Updated working hours');

        return back()->with('success', __('app.settings.working_hours.saved'));
    }
}
