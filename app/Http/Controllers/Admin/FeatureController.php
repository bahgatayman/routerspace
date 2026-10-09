<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Owner;
use App\Services\AdminAuditLogger;
use Inertia\Inertia;
use Inertia\Response;

class FeatureController extends Controller
{
    public function index(): Response
    {
        $features = Feature::withCount('owners')->get();
        $owners = Owner::with('features')->get();

        return Inertia::render('Admin/Features/Index', [
            'features' => $features->map(fn (Feature $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'key' => $f->key,
                'icon' => $f->icon,
                'description' => $f->description,
                'owners_count' => $f->owners_count,
                'is_active' => (bool) $f->is_active,
            ])->values(),
            'owners' => $owners->map(fn (Owner $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'business_name' => $o->business_name,
                'feature_ids' => $o->features->pluck('id')->values(),
            ])->values(),
        ]);
    }

    public function toggleGlobal($featureId)
    {
        $feature = Feature::findOrFail($featureId);
        $feature->update(['is_active' => ! $feature->is_active]);

        app(AdminAuditLogger::class)->log(
            $feature->is_active ? 'feature.enabled_globally' : 'feature.disabled_globally',
            null,
            "Feature '{$feature->name}' ".($feature->is_active ? 'enabled' : 'disabled').' for all businesses',
            ['feature' => $feature->key],
        );

        return back()->with(
            'success',
            "Feature '{$feature->name}' ".($feature->is_active ? 'enabled' : 'disabled').' globally.'
        );
    }

    public function toggleForOwner($ownerId, $featureId)
    {
        $owner = Owner::findOrFail($ownerId);
        $feature = Feature::findOrFail($featureId);

        if ($owner->features()->where('feature_id', $featureId)->exists()) {
            $owner->disableFeature($feature->key);
            $message = "'{$feature->name}' disabled for {$owner->business_name}";
        } else {
            $owner->enableFeature($feature->key);
            $message = "'{$feature->name}' enabled for {$owner->business_name}";
        }

        app(AdminAuditLogger::class)->log('feature.toggled_for_owner', $owner, $message, ['feature' => $feature->key]);

        return back()->with('success', $message);
    }
}
