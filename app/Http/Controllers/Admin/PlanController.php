<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Services\AdminAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::withCount([
            'owners',
            'owners as active_owners_count' => fn ($q) => $q->where('is_active', true)->where('subscription_expires_at', '>', now()),
            'subscriptions',
        ])
            ->withSum('subscriptions as revenue', 'amount_paid')
            ->orderBy('sort_order')
            ->get();
        $requests = SubscriptionRequest::selectRaw('plan_id, COUNT(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return view('admin.plans.index', ['plans' => $plans, 'requests' => $requests, 'featureNames' => Feature::pluck('name', 'key')]);
    }

    public function show(int $plan): View
    {
        $plan = Plan::withCount(['owners', 'subscriptions'])->withSum('subscriptions as revenue', 'amount_paid')->findOrFail($plan);
        $active = $plan->owners()->where('is_active', true)->where('subscription_expires_at', '>', now())->count();

        return view('admin.plans.show', [
            'plan' => $plan,
            'active' => $active,
            'mrr' => $active * (float) $plan->price_per_month,
            'requests' => SubscriptionRequest::where('plan_id', $plan->id)->count(),
            'features' => Feature::orderBy('id')->get(['key', 'name']),
            'owners' => $plan->owners()->withCount(['rooms', 'products'])->orderBy('business_name')->paginate(20),
            'payments' => Subscription::with(['owner:id,business_name,name', 'admin:id,name'])->where('plan_id', $plan->id)->latest()->take(10)->get(),
            'targets' => Plan::where('id', '!=', $plan->id)->orderBy('sort_order')->get(['id', 'name', 'is_active', 'price_per_month']),
            'used' => $this->usage($plan),
        ]);
    }

    public function create(): View
    {
        $features = Feature::where('is_active', true)->orderBy('id')->get();

        return view('admin.plans.create', compact('features'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);

        $plan = Plan::create($validated);
        app(AdminAuditLogger::class)->log('plan.created', null, "Plan '{$plan->name}' created", ['plan_id' => $plan->id]);

        return redirect()->route('admin.plans.show', $plan->id)->with('success', __('app.admin_platform.plan_msg.created'));
    }

    public function edit(int $id): View
    {
        $plan = Plan::findOrFail($id);
        $features = Feature::where('is_active', true)->orderBy('id')->get();

        return view('admin.plans.edit', compact('plan', 'features'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $validated = $this->validatePlan($request, $id);

        $plan = Plan::findOrFail($id);
        $before = $plan->only(['name', 'price_per_month', 'max_members', 'max_workspaces', 'max_rooms', 'max_products']);
        $plan->update($validated);
        app(AdminAuditLogger::class)->log('plan.updated', null, "Plan '{$plan->name}' updated", ['plan_id' => $plan->id, 'before' => $before]);

        return redirect()->route('admin.plans.show', $plan->id)->with('success', __('app.admin_platform.plan_msg.updated'));
    }

    /** Shared validation + normalisation for create/update. */
    private function validatePlan(Request $request, ?int $id = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|unique:plans,slug'.($id ? ",{$id}" : ''),
            'max_members' => 'required|integer|min:1',
            'max_workspaces' => 'required|integer|min:0',
            'max_rooms' => 'required|integer|min:0',
            'max_products' => 'required|integer|min:0',
            'price_per_month' => 'required|numeric|min:0',
            'sort_order' => 'integer|min:0',
            'features' => 'nullable|array',
            'features.*' => 'string|exists:features,key',
        ]);

        // Always persist the feature set (empty when nothing is checked).
        $validated['features'] = $request->input('features', []);

        return $validated;
    }

    public function toggle(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $plan->update(['is_active' => ! $plan->is_active]);
        app(AdminAuditLogger::class)->log($plan->is_active ? 'plan.enabled' : 'plan.disabled', null, "Plan '{$plan->name}' ".($plan->is_active ? 'enabled' : 'disabled'), ['plan_id' => $plan->id], $request->input('reason'));

        $msg = __($plan->is_active ? 'app.admin_platform.plan_msg.enabled' : 'app.admin_platform.plan_msg.disabled');

        return $request->wantsJson() ? response()->json(['ok' => true, 'is_active' => $plan->is_active, 'message' => $msg]) : back()->with('success', $msg);
    }

    /**
     * Delete — only a plan nothing has ever referenced (no business on it, no
     * recorded subscription, no renewal request). Anything else keeps its
     * history: the admin deactivates it or moves its businesses instead.
     */
    public function destroy(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $plan = Plan::findOrFail($id);

        $deleted = DB::transaction(function () use ($plan) {
            $locked = Plan::whereKey($plan->id)->lockForUpdate()->first();
            if (! $locked || array_sum($this->usage($locked)) > 0) {
                return false;
            }
            $locked->delete();

            return true;
        });

        if (! $deleted) {
            $msg = __('app.admin_platform.plan_msg.in_use');

            return $request->wantsJson() ? response()->json(['ok' => false, 'message' => $msg, 'usage' => $this->usage($plan)], 422) : back()->with('error', $msg);
        }

        app(AdminAuditLogger::class)->log('plan.deleted', null, "Plan '{$plan->name}' deleted", ['plan_id' => $plan->id, 'plan' => $plan->only(['name', 'slug', 'price_per_month'])], $request->input('reason'));
        $msg = __('app.admin_platform.plan_msg.deleted');

        return $request->wantsJson() ? response()->json(['ok' => true, 'message' => $msg]) : redirect()->route('admin.plans.index')->with('success', $msg);
    }

    /**
     * Move every business on this plan to another plan: owners.plan_id and the
     * plan's feature set (applyPlanFeatures) change; subscription dates and
     * past payments are untouched. One transaction, one audit entry per business.
     */
    public function migrate(Request $request, int $id): RedirectResponse|JsonResponse
    {
        $plan = Plan::findOrFail($id);
        $data = $request->validate([
            'target_plan_id' => ['required', 'integer', 'exists:plans,id', 'not_in:'.$plan->id],
            'deactivate' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], ['target_plan_id' => __('app.admin_platform.target_plan')]);
        $target = Plan::findOrFail($data['target_plan_id']);
        $logger = app(AdminAuditLogger::class);

        $moved = DB::transaction(function () use ($plan, $target, $data, $logger) {
            $owners = Owner::where('plan_id', $plan->id)->lockForUpdate()->get();
            foreach ($owners as $owner) {
                $owner->update(['plan_id' => $target->id]);
                $owner->setRelation('plan', $target);
                $owner->applyPlanFeatures();
                $logger->log('owner.plan_changed', $owner, "Moved from plan '{$plan->name}' to '{$target->name}'", ['from_plan_id' => $plan->id, 'to_plan_id' => $target->id], $data['reason'] ?? null);
            }
            if (! empty($data['deactivate'])) {
                $plan->update(['is_active' => false]);
            }

            return $owners->count();
        });

        $logger->log('plan.migrated', null, "Moved {$moved} business(es) from plan '{$plan->name}' to '{$target->name}'", ['from_plan_id' => $plan->id, 'to_plan_id' => $target->id, 'count' => $moved, 'deactivated' => ! empty($data['deactivate'])], $data['reason'] ?? null);
        $msg = trans_choice('app.admin_platform.plan_msg.migrated', $moved, ['count' => $moved, 'plan' => $target->name]);

        return $request->wantsJson() ? response()->json(['ok' => true, 'moved' => $moved, 'message' => $msg]) : redirect()->route('admin.plans.show', $plan->id)->with('success', $msg);
    }

    /** @return array{owners: int, subscriptions: int, requests: int} */
    private function usage(Plan $plan): array
    {
        return [
            'owners' => Owner::where('plan_id', $plan->id)->count(),
            'subscriptions' => Subscription::where('plan_id', $plan->id)->count(),
            'requests' => SubscriptionRequest::where('plan_id', $plan->id)->count(),
        ];
    }
}
