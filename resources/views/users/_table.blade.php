{{--
    Users table + pagination, extracted so the live-search JS in index.blade.php
    can swap just this fragment via fetch() instead of reloading the page.
    $users: LengthAwarePaginator<HotspotUser> (MemberSearchService-ranked).
    $search: the raw (trimmed) search term, for highlighting + empty state.
--}}
@if ($users->count() > 0)
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                <tr>
                    <th class="px-4 py-3">{{ __('app.table.th.name') }}</th>
                    <th class="px-4 py-3">{{ __('app.table.th.phone') }}</th>
                    @if($owner->hasFeature('hotspot'))
                    <th class="px-4 py-3">{{ __('app.table.th.download') }}</th>
                    <th class="px-4 py-3">{{ __('app.table.th.upload') }}</th>
                    @endif
                    <th class="px-4 py-3">{{ __('app.table.th.status') }}</th>
                    <th class="px-4 py-3">{{ __('app.table.th.created') }}</th>
                    <th class="px-4 py-3">{{ __('app.table.th.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($users as $user)
                    <tr class="row-link hover:bg-gray-50 transition cursor-pointer" data-href="/users/{{ $user->id }}">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{-- Real link: keeps the row reachable by keyboard and ctrl/middle-clickable. --}}
                            <a href="/users/{{ $user->id }}" class="hover:text-blue-600">{!! \App\Support\Highlight::mark($user->name, $search) !!}</a>
                            @if ($user->relationLoaded('packages') && ($pkg = $user->packages->first()))
                                @php $left = \App\Support\Duration::label($user->packages->sum(fn ($p) => $p->remainingMinutes())); @endphp
                                <div><span class="ls-pkg-pill {{ $pkg->isExpiringSoon() ? 'is-soon' : '' }}" title="{{ $user->packages->pluck('name')->implode(', ') }}">
                                    <x-ui.icon name="clock" />{{ $pkg->isExpiringSoon() ? __('app.packages.expiring_left', ['time' => $left]) : __('app.packages.left', ['time' => $left]) }}
                                </span></div>
                            @endif
                        </td>
                        <td class="px-4 py-3"><bdi dir="ltr">{!! \App\Support\Highlight::mark($user->phone, $search) !!}</bdi></td>
                        @if($owner->hasFeature('hotspot'))
                        <td class="px-4 py-3">{{ $user->speed_download }}</td>
                        <td class="px-4 py-3">{{ $user->speed_upload }}</td>
                        @endif
                        <td class="px-4 py-3">
                            @if ($user->status === 'active')
                                <span class="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-medium">{{ __('app.status.active') }}</span>
                            @else
                                <span class="bg-red-100 text-red-700 px-2 py-1 rounded-full text-xs font-medium">{{ __('app.status.inactive') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->created_at->format('M d, Y') }}</td>
                        <td class="px-4 py-3 flex gap-2">
                            <a href="/users/{{ $user->id }}/edit" class="text-blue-600 hover:underline text-sm font-medium">{{ __('app.common.edit') }}</a>
                            <form method="POST" action="/users/{{ $user->id }}" onsubmit="return confirm('Delete this user?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-sm font-medium">{{ __('app.common.delete') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->withQueryString()->links() }}
    </div>
@elseif ($search !== '')
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
        <p class="text-gray-500 text-lg">{{ __('app.user.no_match') }}</p>
    </div>
@else
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
        <p class="text-gray-500 text-lg">{{ __('app.empty.no_users') }}</p>
    </div>
@endif
