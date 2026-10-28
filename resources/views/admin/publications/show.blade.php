@extends('layouts.admin')

@section('page-title', __('app.publication.admin.title'))

@php
    use App\Models\Workspace;
    use App\Services\WorkspacePublicationService as Publication;
    $p = fn (string $k, array $r = []) => __('app.publication.'.$k, $r);
    $a = fn (string $k, array $r = []) => __('app.publication.admin.'.$k, $r);
    $status = $workspace->publicationStatus();
    $tones = [Workspace::DRAFT => 'neutral', Workspace::PENDING_REVIEW => 'info', Workspace::REJECTED => 'warn', Workspace::PUBLISHED => 'ok', Workspace::UNPUBLISHED => 'danger'];
    $isLive = app(\App\Services\PublicWorkspaceDirectory::class)->isVisible($workspace);
@endphp

@section('content')
<div class="ls-adm">
    <a href="{{ route('admin.publications.index', ['status' => $status === Workspace::DRAFT ? null : $status]) }}" class="ls-link">← {{ $a('back') }}</a>

    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $workspace->name }} <x-ui.badge :tone="$tones[$status] ?? 'neutral'">{{ $p('status.'.$status) }}</x-ui.badge></h1>
            <p class="ls-subtitle">
                <a href="{{ route('admin.business.show', $workspace->owner_id) }}" class="ls-link">{{ $workspace->owner?->business_name ?: $workspace->owner?->name }}</a>
                · {{ $workspace->owner?->name }} · <bdi dir="ltr">{{ $workspace->owner?->email }}</bdi>
                @if ($workspace->slug) · <bdi dir="ltr">/workspaces/{{ $workspace->slug }}</bdi>@endif
            </p>
            @if ($status === Workspace::PUBLISHED)
                <p class="ls-hint" style="margin-top:4px">{{ $isLive ? $a('live') : $a('not_live') }}</p>
            @endif
        </div>
    </header>

    <div class="ls-pubs">
        <div class="ls-adm">
            <section class="ls-card" aria-labelledby="pubs-details">
                <div class="ls-card-head"><h2 class="ls-card-title" id="pubs-details">{{ $a('details') }}</h2></div>
                <div class="ls-card-body" style="display:grid;gap:var(--space-4)">
                    <div>
                        <div class="ls-label">{{ $a('address') }}</div>
                        <p style="margin:2px 0 0" dir="auto">{{ $workspace->address ?: '—' }}{{ $workspace->city ? ' · '.$workspace->city : '' }}</p>
                    </div>
                    <div>
                        <div class="ls-label">{{ $a('description') }}</div>
                        <p class="ls-pubs-desc" dir="auto">{{ $workspace->description ?: '—' }}</p>
                    </div>
                    <div>
                        <div class="ls-label" style="margin-bottom:6px">{{ $a('photos') }}</div>
                        @if ($workspace->images->isEmpty())
                            <p class="ls-faint">{{ $a('no_photo') }}</p>
                        @else
                            <div class="ls-pubs-photos">
                                @foreach ($workspace->images as $image)
                                    <figure>
                                        <img src="{{ $image->thumbnailUrl() }}" alt="" loading="lazy">
                                        @if ($image->is_primary)<figcaption>{{ __('app.workspace.primary_photo') }}</figcaption>@endif
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <div class="ls-label" style="margin-bottom:6px">{{ $a('rooms') }}</div>
                        @if ($workspace->rooms->isEmpty())
                            <p class="ls-faint">—</p>
                        @else
                            <div class="ls-table-wrap">
                                <table class="ls-table">
                                    <tbody>
                                        @foreach ($workspace->rooms as $room)
                                            <tr>
                                                <th scope="row" style="font-weight:500"><a href="{{ route('admin.rooms.show', $room->id) }}" class="ls-link">{{ $room->name }}</a></th>
                                                <td>{{ $room->typeLabel() }}</td>
                                                <td class="is-num">{{ $room->pricingSummary() }}</td>
                                                <td>
                                                    @if ($room->is_available && $room->hasDisplayablePrice())
                                                        <x-ui.badge tone="ok">{{ __('app.admin_platform.available') }}</x-ui.badge>
                                                    @else
                                                        <x-ui.badge tone="neutral">{{ $a('not_bookable') }}</x-ui.badge>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            <section class="ls-card" aria-labelledby="pubs-history">
                <div class="ls-card-head"><h2 class="ls-card-title" id="pubs-history">{{ $a('history') }}</h2></div>
                <div class="ls-card-body">
                    @if ($workspace->publicationReviews->isEmpty())
                        <p class="ls-faint">{{ $p('no_history') }}</p>
                    @else
                        <ol class="ls-pub-history">
                            @foreach ($workspace->publicationReviews as $entry)
                                <li>
                                    <span class="ls-pub-history-action">{{ $p('actions.'.$entry->action) }}</span>
                                    <span class="ls-faint">· {{ $entry->actor_name ?: '—' }} ({{ $entry->actor_type === 'admin' ? $p('team') : $a('owner_actor') }}) · {{ $entry->created_at->translatedFormat('M j, Y g:i A') }}</span>
                                    @if ($entry->reason)<p class="ls-pub-history-reason" dir="auto">{{ $entry->reason }}</p>@endif
                                    @if (! empty($entry->readiness))
                                        <p class="ls-pub-history-reason">{{ collect($entry->readiness)->map(fn ($k) => $p('checks.'.$k.'.label'))->implode(' · ') }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>
        </div>

        <div class="ls-adm">
            <section class="ls-card" aria-labelledby="pubs-ready">
                <div class="ls-card-head"><h2 class="ls-card-title" id="pubs-ready">{{ $a('readiness') }}</h2></div>
                <div class="ls-card-body">
                    <ul class="ls-pub-checks">
                        @foreach (\App\Services\WorkspaceReadinessService::CHECKS as $key)
                            @php $ok = $readiness['checks'][$key]; @endphp
                            <li class="ls-pub-check {{ $ok ? 'is-ok' : 'is-missing' }}" data-check="{{ $key }}">
                                <span class="ls-pub-check-icon" aria-hidden="true">@if ($ok)<x-ui.icon name="check" />@else<x-ui.icon name="x" />@endif</span>
                                <span class="ls-pub-check-text"><b>{{ $p('checks.'.$key.'.label') }}</b></span>
                                <span class="ls-sr">{{ $ok ? '✓' : '✗' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>

            @if (Publication::allows('approved', $status) || Publication::allows('unpublished', $status))
                <section class="ls-card">
                    <div class="ls-card-body">
                        @if (Publication::allows('approved', $status))
                            <form method="POST" action="{{ route('admin.publications.approve', $workspace) }}" class="ls-pubs-form" onsubmit="return confirm(@js($a('approve_confirm')))">
                                @csrf
                                <button type="submit" class="ls-btn ls-btn--primary" @disabled(! $readiness['ready'])>{{ $a('approve') }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.publications.reject', $workspace) }}" class="ls-pubs-form">
                                @csrf
                                <label class="ls-label" for="reject-reason">{{ $a('reject_reason') }}</label>
                                <textarea id="reject-reason" name="reason" rows="4" class="ls-textarea @error('reason') is-invalid @enderror" required minlength="5" maxlength="1000" placeholder="{{ $a('reason_ph') }}">{{ old('reason') }}</textarea>
                                @error('reason')<p class="ls-error">{{ $message }}</p>@enderror
                                <button type="submit" class="ls-btn ls-btn--secondary">{{ $a('reject') }}</button>
                            </form>
                        @endif
                        @if (Publication::allows('unpublished', $status))
                            <form method="POST" action="{{ route('admin.publications.unpublish', $workspace) }}" class="ls-pubs-form">
                                @csrf
                                <label class="ls-label" for="unpublish-reason">{{ $a('unpublish_reason') }}</label>
                                <textarea id="unpublish-reason" name="reason" rows="4" class="ls-textarea @error('reason') is-invalid @enderror" required minlength="5" maxlength="1000" placeholder="{{ $a('reason_ph') }}">{{ old('reason') }}</textarea>
                                @error('reason')<p class="ls-error">{{ $message }}</p>@enderror
                                <button type="submit" class="ls-btn ls-btn--danger-quiet">{{ $a('unpublish') }}</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
@endsection
