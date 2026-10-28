{{--
    Public listing (publication) card on the workspace edit page.
    Expects: $workspace, $readiness (WorkspaceReadinessService::check), $publicationHistory, $canPublish.
    Buttons are only hints — WorkspacePublicationService re-checks every rule server-side.
--}}
@php
    use App\Models\Workspace;
    use App\Services\WorkspacePublicationService as Publication;
    $p = fn (string $k, array $r = []) => __('app.publication.'.$k, $r);
    $status = $workspace->publicationStatus();
    $tone = [Workspace::DRAFT => 'neutral', Workspace::PENDING_REVIEW => 'info', Workspace::REJECTED => 'warn', Workspace::PUBLISHED => 'ok', Workspace::UNPUBLISHED => 'danger'][$status] ?? 'neutral';
    $canSubmit = $canPublish && Publication::allows('submitted', $status);
    $canWithdraw = $canPublish && Publication::allows('withdrawn', $status);
    // The latest admin note explains a rejected / unpublished state.
    $latestNote = in_array($status, [Workspace::REJECTED, Workspace::UNPUBLISHED], true)
        ? $publicationHistory->first(fn ($r) => $r->to_status === $status && filled($r->reason))
        : null;
    $fixLinks = [
        'name' => '#workspace-details', 'location' => '#workspace-details', 'description' => '#workspace-details',
        'primary_image' => '#workspace-photos',
        'priced_room' => route('workspaces.index', ['workspace' => $workspace->id]),
        'subscription' => route('subscription.plans'),
    ];
    $missingFromSubmit = (array) session('publication_missing', []);
@endphp

<section class="ls-card mt-6 ls-pub" id="workspace-publication" style="scroll-margin-top: var(--space-6)" aria-labelledby="pub-title">
    <div class="ls-card-head">
        <div>
            <h2 class="ls-card-title" id="pub-title">{{ $p('title') }}</h2>
            <p class="ls-hint" style="margin-top: 2px">{{ $p('subtitle') }}</p>
        </div>
        <x-ui.badge :tone="$tone" data-publication-status="{{ $status }}">{{ $p('status.'.$status) }}</x-ui.badge>
    </div>

    <div class="ls-card-body ls-pub-body">
        <p class="ls-pub-status-text">{{ $p('status_text.'.$status) }}</p>

        @if ($latestNote)
            <div class="ls-pub-note" role="note">
                <strong>{{ $p('reason_label') }}</strong>
                <p dir="auto">{{ $latestNote->reason }}</p>
            </div>
        @endif

        @if ($workspace->slug && $status === Workspace::PUBLISHED)
            <p class="ls-pub-address"><span>{{ $p('public_address') }}:</span> <bdi dir="ltr">/workspaces/{{ $workspace->slug }}</bdi></p>
        @endif

        <div>
            <h3 class="ls-pub-subtitle">{{ $p('checklist') }}</h3>
            <ul class="ls-pub-checks">
                @foreach (\App\Services\WorkspaceReadinessService::CHECKS as $key)
                    @php $ok = $readiness['checks'][$key]; @endphp
                    <li class="ls-pub-check {{ $ok ? 'is-ok' : 'is-missing' }} {{ in_array($key, $missingFromSubmit, true) ? 'is-flagged' : '' }}" data-check="{{ $key }}">
                        <span class="ls-pub-check-icon" aria-hidden="true">
                            @if ($ok)<x-ui.icon name="check" />@else<x-ui.icon name="x" />@endif
                        </span>
                        <span class="ls-pub-check-text">
                            <b>{{ $p('checks.'.$key.'.label') }}</b>
                            @unless ($ok)<small>{{ $p('checks.'.$key.'.fix') }}</small>@endunless
                        </span>
                        @if (! $ok && isset($fixLinks[$key]))
                            <a href="{{ $fixLinks[$key] }}" class="ls-link ls-pub-fix">{{ $p('fix') }}</a>
                        @endif
                        <span class="ls-sr">{{ $ok ? '✓' : '✗' }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($readiness['ready'])<p class="ls-pub-ready"><x-ui.icon name="check-circle" /> {{ $p('all_ready') }}</p>@endif
        </div>

        @if ($canPublish)
            <div class="ls-pub-actions">
                @if (Publication::allows('submitted', $status))
                    <form method="POST" action="{{ route('workspaces.publication.submit', $workspace) }}">
                        @csrf
                        <x-ui.button type="submit" variant="primary" icon="arrow-right" :disabled="! $readiness['ready']">
                            {{ $status === Workspace::DRAFT ? $p('submit') : $p('resubmit') }}
                        </x-ui.button>
                    </form>
                    @unless ($readiness['ready'])<p class="ls-hint">{{ $p('not_ready_hint') }}</p>@endunless
                @endif
                @if ($canWithdraw)
                    <form method="POST" action="{{ route('workspaces.publication.withdraw', $workspace) }}" onsubmit="return confirm(@js($p('withdraw_confirm')))">
                        @csrf
                        <x-ui.button type="submit" variant="ghost">{{ $p('withdraw') }}</x-ui.button>
                    </form>
                @endif
            </div>
        @elseif (Publication::allows('submitted', $status) || Publication::allows('withdrawn', $status))
            <p class="ls-hint">{{ $p('owner_only') }}</p>
        @endif

        <div>
            <h3 class="ls-pub-subtitle">{{ $p('history') }}</h3>
            @if ($publicationHistory->isEmpty())
                <p class="ls-faint">{{ $p('no_history') }}</p>
            @else
                <ol class="ls-pub-history">
                    @foreach ($publicationHistory as $entry)
                        <li>
                            <span class="ls-pub-history-action">{{ $p('actions.'.$entry->action) }}</span>
                            <span class="ls-faint">· {{ $entry->actor_type === 'admin' ? $p('team') : $entry->actor_name }} · {{ $entry->created_at->diffForHumans() }}</span>
                            @if ($entry->reason)<p class="ls-pub-history-reason" dir="auto">{{ $entry->reason }}</p>@endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</section>
