<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Owner;
use App\Models\Staff;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Public "rate Link Space Panel" page. No login needed; when a business user
 * IS signed in, the response is linked to their business from the session
 * (never from anything in the form).
 *
 * Spam handling stays invisible to real people: a honeypot field, a minimum
 * time on the page (signed render timestamp), a per-IP rate limit and a short
 * duplicate window. Bots that trip the honeypot/timer get the normal thank-you
 * page so they learn nothing, but nothing is stored.
 */
class FeedbackController extends Controller
{
    private const LOCALES = ['en', 'ar'];

    private const MIN_SECONDS_ON_PAGE = 3;

    private const PER_10_MIN = 5;

    private const PER_DAY = 20;

    private const DUPLICATE_WINDOW_SECONDS = 600;

    public function create(Request $request): View|RedirectResponse
    {
        // Shareable language links: /feedback?lang=ar → remember it, then a clean URL.
        if (in_array($request->query('lang'), self::LOCALES, true)) {
            $request->session()->put('locale', $request->query('lang'));

            return redirect()->route('feedback.create');
        }

        // First visit with no language chosen yet: follow the browser.
        if (! $request->session()->has('locale')) {
            app()->setLocale($request->getPreferredLanguage(self::LOCALES) ?? config('app.locale', 'en'));
        }

        [$owner, $staff] = $this->signedInActor();

        return view('feedback.create', [
            'sent' => (bool) $request->session()->get('feedback_sent'),
            'startedAt' => Crypt::encryptString((string) now()->timestamp),
            'signedInAs' => $staff?->name ?? $owner?->name,
            'businessName' => $owner?->business_name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->has('locale')) {
            app()->setLocale($request->getPreferredLanguage(self::LOCALES) ?? config('app.locale', 'en'));
        }

        $ipKey = 'feedback:'.sha1((string) $request->ip());
        if (RateLimiter::tooManyAttempts($ipKey.':10m', self::PER_10_MIN) || RateLimiter::tooManyAttempts($ipKey.':day', self::PER_DAY)) {
            return back()->withInput()->withErrors(['form' => __('app.feedback.errors.too_many')]);
        }

        if ($this->looksAutomated($request)) {
            return $this->thanks(); // silently dropped
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'category' => ['required', 'string', Rule::in(Feedback::CATEGORIES)],
            'product_area' => ['nullable', 'string', Rule::in(Feedback::AREAS)],
            'message' => ['required', 'string', 'min:'.Feedback::MESSAGE_MIN, 'max:'.Feedback::MESSAGE_MAX],
            'details' => ['nullable', 'string', 'max:'.Feedback::MESSAGE_MAX],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
        ], $this->messages());

        RateLimiter::hit($ipKey.':10m', 600);
        RateLimiter::hit($ipKey.':day', 86400);

        // Same text from the same address within a few minutes = a double submit.
        $fingerprint = 'feedback:dup:'.sha1($request->ip().'|'.mb_strtolower(trim(preg_replace('/\s+/u', ' ', $data['message']))));
        if (! Cache::add($fingerprint, 1, self::DUPLICATE_WINDOW_SECONDS)) {
            return $this->thanks();
        }

        [$owner, $staff] = $this->signedInActor();

        Feedback::create([
            'rating' => (int) $data['rating'],
            'category' => $data['category'],
            'product_area' => $data['product_area'] ?? null,
            'message' => trim($data['message']),
            // The follow-up question only exists for some categories.
            'details' => in_array($data['category'], Feedback::CATEGORIES_WITH_DETAILS, true) && filled($data['details'] ?? null) ? trim($data['details']) : null,
            'contact_name' => filled($data['contact_name'] ?? null) ? trim($data['contact_name']) : null,
            'contact_email' => filled($data['contact_email'] ?? null) ? mb_strtolower(trim($data['contact_email'])) : null,
            'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
            'owner_id' => $owner?->id,
            'staff_id' => $staff?->id,
        ]);

        return $this->thanks();
    }

    /**
     * Every rule has its own message from lang/{en,ar}/app.php: the project has
     * no lang/ar/validation.php, so Laravel's defaults would show English on the
     * Arabic page.
     */
    private function messages(): array
    {
        $min = ['min' => Feedback::MESSAGE_MIN];
        $tooLong = __('app.feedback.errors.too_long', ['max' => Feedback::MESSAGE_MAX]);

        return [
            'rating.*' => __('app.feedback.errors.rating'),
            'category.*' => __('app.feedback.errors.category'),
            'product_area.*' => __('app.feedback.errors.area'),
            'message.required' => __('app.feedback.errors.message', $min),
            'message.string' => __('app.feedback.errors.message', $min),
            'message.min' => __('app.feedback.errors.message', $min),
            'message.max' => $tooLong,
            'details.*' => $tooLong,
            'contact_name.*' => __('app.feedback.errors.name'),
            'contact_email.*' => __('app.feedback.errors.email'),
        ];
    }

    private function thanks(): RedirectResponse
    {
        return redirect()->route('feedback.create')->with('feedback_sent', true);
    }

    /** Honeypot filled, or the form came back faster than a person could fill it (or without a valid render stamp). */
    private function looksAutomated(Request $request): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }

        try {
            $started = (int) Crypt::decryptString((string) $request->input('started_at'));
        } catch (DecryptException) {
            return true;
        }

        return now()->timestamp - $started < self::MIN_SECONDS_ON_PAGE;
    }

    /** @return array{0: ?Owner, 1: ?Staff} — only from verified sessions. */
    private function signedInActor(): array
    {
        if ($staff = auth('staff')->user()) {
            return [$staff->owner, $staff];
        }

        return [auth('owner')->user(), null];
    }
}
