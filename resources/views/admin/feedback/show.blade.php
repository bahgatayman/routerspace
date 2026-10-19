@extends('layouts.admin')

@section('page-title', __('app.feedback.admin.title'))

@php
    use App\Models\Feedback;
    $a = fn (string $k, array $r = []) => __('app.feedback.admin.'.$k, $r);
    $f = fn (string $k, array $r = []) => __('app.feedback.'.$k, $r);
    $statusTone = ['new' => 'info', 'reviewing' => 'warn', 'planned' => 'info', 'completed' => 'ok', 'rejected' => 'neutral'];
    $catTone = ['general' => 'neutral', 'feature' => 'info', 'bug' => 'danger', 'complaint' => 'warn', 'other' => 'neutral'];
    $detailsLabel = in_array($item->category, Feedback::CATEGORIES_WITH_DETAILS, true) ? $f('details.'.$item->category) : $f('details.attr');
@endphp

@section('content')
<div class="ls-adm">
    <a href="{{ route('admin.feedback.index') }}" class="ls-link ls-back">← {{ $a('back') }}</a>

    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">#{{ $item->id }} · {{ $f('category.options.'.$item->category) }}</h1>
            <p class="ls-subtitle">
                {{ $a('submitted', ['date' => $item->created_at->translatedFormat('M j, Y · g:i A')]) }}
                · {{ $a('written_in', ['language' => $item->locale === 'ar' ? 'العربية' : 'English']) }}
            </p>
        </div>
        <div class="ls-actions"><x-ui.badge :tone="$statusTone[$item->status] ?? 'neutral'">{{ $a('statuses.'.$item->status) }}</x-ui.badge></div>
    </header>

    <div class="ls-fbs">
        {{-- What the visitor submitted (read-only, never edited). --}}
        <section class="ls-card" aria-labelledby="fbs-title">
            <div class="ls-card-body ls-fbs-body">
                <h2 class="sr-only" id="fbs-title">{{ $f('message.label') }}</h2>
                <div class="ls-fbs-rating" aria-label="{{ trans_choice('app.feedback.rating.star', $item->rating, ['count' => $item->rating]) }}">
                    <span class="ls-fbd-stars" aria-hidden="true">@for ($i = 1; $i <= 5; $i++)<x-ui.icon name="star" class="{{ $i <= $item->rating ? 'is-on' : '' }}" />@endfor</span>
                    <b>{{ __('app.feedback.rating.labels.'.$item->rating) }}</b>
                </div>
                <div class="ls-fb-tags">
                    <x-ui.badge :tone="$catTone[$item->category] ?? 'neutral'" :dot="false">{{ $f('category.options.'.$item->category) }}</x-ui.badge>
                    <span class="ls-fb-area">{{ $item->product_area ? $f('area.options.'.$item->product_area) : $a('unspecified') }}</span>
                </div>

                {{-- Escaped output + pre-wrap keeps the visitor's line breaks without trusting any HTML. --}}
                <div class="ls-fbs-text" dir="auto">{{ $item->message }}</div>

                @if ($item->details)
                    <div class="ls-fbs-details">
                        <span class="ls-fbs-k">{{ $detailsLabel }}</span>
                        <div class="ls-fbs-text" dir="auto">{{ $item->details }}</div>
                    </div>
                @endif

                <dl class="ls-fbs-facts">
                    <div>
                        <dt>{{ $a('contact') }}</dt>
                        <dd>
                            @if ($item->contact_name || $item->contact_email)
                                {{ $item->contact_name }}
                                @if ($item->contact_email)
                                    <bdi dir="ltr" class="ls-fbs-email">{{ $item->contact_email }}</bdi>
                                    <a class="ls-link" href="mailto:{{ rawurlencode($item->contact_email) }}?subject={{ rawurlencode('Link Space Panel — '.$f('page_title')) }}">{{ $a('reply') }}</a>
                                @endif
                            @else
                                <span class="ls-faint">{{ $a('no_contact') }}</span>
                            @endif
                        </dd>
                    </div>
                    @if ($item->owner)
                        <div>
                            <dt>{{ $a('business') }}</dt>
                            <dd><a href="{{ route('admin.business.show', $item->owner) }}" class="ls-link">{{ $item->owner->business_name ?: $item->owner->name }}</a>
                                @if ($item->staff)<span class="ls-faint">· {{ $a('signed_in_as') }} {{ $item->staff->name }}</span>@endif
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </section>

        {{-- Super Admin review: separate from (and never overwriting) the submission. --}}
        <section class="ls-card" aria-labelledby="fbs-review">
            <div class="ls-card-head"><h2 class="ls-card-title" id="fbs-review">{{ $a('review') }}</h2></div>
            <form method="POST" action="{{ route('admin.feedback.update', $item) }}" class="ls-card-body ls-fbs-form">
                @csrf
                @method('PUT')
                <div class="ls-field">
                    <label class="ls-label" for="fbs-status">{{ $a('status') }}</label>
                    <select id="fbs-status" name="status" class="ls-select @error('status') is-invalid @enderror">
                        @foreach (Feedback::STATUSES as $s)<option value="{{ $s }}" @selected(old('status', $item->status) === $s)>{{ $a('statuses.'.$s) }}</option>@endforeach
                    </select>
                    @error('status')<p class="ls-error">{{ $message }}</p>@enderror
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="fbs-notes">{{ $a('notes') }}</label>
                    <textarea id="fbs-notes" name="admin_notes" rows="6" maxlength="5000" class="ls-textarea @error('admin_notes') is-invalid @enderror">{{ old('admin_notes', $item->admin_notes) }}</textarea>
                    <p class="ls-hint">{{ $a('notes_hint') }}</p>
                    @error('admin_notes')<p class="ls-error">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="ls-btn ls-btn--primary">{{ $a('save') }}</button>
                <p class="ls-faint ls-fbs-reviewed">
                    @if ($item->reviewed_at)
                        {{ $a('reviewed_by', ['name' => $item->reviewer?->name ?? '—', 'when' => $item->reviewed_at->diffForHumans()]) }}
                    @else
                        {{ $a('not_reviewed') }}
                    @endif
                </p>
            </form>
        </section>
    </div>
</div>
@endsection
