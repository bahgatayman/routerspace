<?php

namespace App\Http\Controllers;

use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Owner-only onboarding actions (routed under auth:owner). All state lives on the
 * signed-in owner's own row; there is no id in any route, so one workspace can
 * never read or change another's onboarding state. Task completion itself is
 * never written here — OnboardingService derives it from real data.
 */
class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $onboarding) {}

    /** Finish / close the Welcome Wizard. Optional `next` must be one of the owner's own task links. */
    public function dismissWelcome(Request $request): JsonResponse|RedirectResponse
    {
        $owner = auth('owner')->user();
        $this->onboarding->markWelcomed($owner);

        $allowed = collect($this->onboarding->tasks($owner))->pluck('url')->all();
        $next = in_array($request->input('next'), $allowed, true) ? $request->input('next') : '/dashboard';

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'next' => $next])
            : redirect($next);
    }

    /** Wizard step "Name your space": the only detail we ask for (sign-up no longer collects it). */
    public function saveProfile(Request $request): JsonResponse|RedirectResponse
    {
        // This app renders validation failures as redirects everywhere except api/*
        // (bootstrap/app.php), so the wizard's fetch gets an explicit 422 here.
        $validator = Validator::make($request->all(), ['business_name' => ['required', 'string', 'max:255']], [],
            ['business_name' => __('app.onboarding.business_name')]);
        if ($validator->fails() && $request->expectsJson()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validate();
        $owner = auth('owner')->user();
        $owner->forceFill(['business_name' => trim($data['business_name'])])->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'business_name' => $owner->business_name])
            : back()->with('success', __('app.onboarding.profile_saved'));
    }

    public function hideChecklist(Request $request): JsonResponse|RedirectResponse
    {
        $this->onboarding->setChecklistHidden(auth('owner')->user(), true);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function showChecklist(Request $request): JsonResponse|RedirectResponse
    {
        $this->onboarding->setChecklistHidden(auth('owner')->user(), false);

        return $request->expectsJson() ? response()->json(['ok' => true]) : redirect('/dashboard');
    }

    /** Intentional replay: shows the Welcome Wizard again on the next dashboard visit. */
    public function restartWelcome(): RedirectResponse
    {
        $this->onboarding->restartWelcome(auth('owner')->user());

        return redirect('/dashboard');
    }
}
