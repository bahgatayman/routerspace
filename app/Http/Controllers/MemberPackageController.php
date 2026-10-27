<?php

namespace App\Http\Controllers;

use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\PackageTemplate;
use App\Services\ActivityLogger;
use App\Services\HourPackageService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Assign (sell) and cancel a member's hour packages — from the member profile. */
class MemberPackageController extends Controller
{
    public function __construct(private HourPackageService $packages, private ActivityLogger $activityLogger) {}

    /**
     * From a template (its terms prefilled in the modal, still editable) or
     * fully custom. Whatever is submitted here is the snapshot the member keeps.
     */
    public function store(Request $request, int $id): RedirectResponse
    {
        $ownerId = TenantContext::id();
        $member = HotspotUser::where('owner_id', $ownerId)->findOrFail($id);

        $v = $request->validate(HourPackageService::saleRules($ownerId));

        $template = ! empty($v['package_template_id'])
            ? PackageTemplate::where('owner_id', $ownerId)->findOrFail($v['package_template_id'])
            : null;

        if ($template && ! $template->is_active) {
            return back()->withInput()->with('error', __('app.packages.errors.template_inactive'));
        }

        $pkg = $this->packages->sell($member, $v, $template);

        $this->activityLogger->log('package.assigned', $member, "Assigned hour package \"{$pkg->name}\" to {$member->name}");

        return redirect('/users/'.$member->id.'#packages')->with('success', __('app.packages.assigned', ['name' => $pkg->name]));
    }

    /** Stops future use. Usage history and already-covered bookings are kept as they are. */
    public function cancel(Request $request, int $id): RedirectResponse
    {
        $pkg = MemberPackage::where('owner_id', TenantContext::id())->with('member')->findOrFail($id);

        if ($pkg->cancelled_at) {
            return back()->with('error', __('app.packages.errors.already_cancelled'));
        }

        $v = $request->validate(['reason' => 'nullable|string|max:255']);
        $this->packages->cancel($pkg, $v['reason'] ?? null);

        $this->activityLogger->log('package.cancelled', $pkg->member, "Cancelled hour package \"{$pkg->name}\" for {$pkg->member?->name}");

        return redirect('/users/'.$pkg->hotspot_user_id.'#packages')->with('success', __('app.packages.cancelled_msg'));
    }
}
