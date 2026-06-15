<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Owner;
use Illuminate\Http\Request;

class FeatureController extends Controller
{
    public function index()
    {
        $features = Feature::withCount('owners')->get();
        $owners   = Owner::with('features')->get();

        return view('admin.features.index', compact('features', 'owners'));
    }

    public function toggleGlobal($featureId)
    {
        $feature = Feature::findOrFail($featureId);
        $feature->update(['is_active' => !$feature->is_active]);

        return back()->with(
            'success',
            "Feature '{$feature->name}' " . ($feature->is_active ? 'enabled' : 'disabled') . ' globally.'
        );
    }

    public function toggleForOwner($ownerId, $featureId)
    {
        $owner   = Owner::findOrFail($ownerId);
        $feature = Feature::findOrFail($featureId);

        if ($owner->features()->where('feature_id', $featureId)->exists()) {
            $owner->disableFeature($feature->key);
            $message = "'{$feature->name}' disabled for {$owner->business_name}";
        } else {
            $owner->enableFeature($feature->key);
            $message = "'{$feature->name}' enabled for {$owner->business_name}";
        }

        return back()->with('success', $message);
    }
}
