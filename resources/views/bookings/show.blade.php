@extends('layouts.app')

@section('page-title', __('app.booking.bookings') . ' #' . str_pad($booking->id, 4, '0', STR_PAD_LEFT))

@php
    $deleteBookingStaff = auth('staff')->user();
    $canDeleteBooking = ! $deleteBookingStaff || $deleteBookingStaff->hasPermission('bookings.delete');
    // On-load snapshot, not a live ticker — this page has no existing
    // per-second ticker infrastructure (unlike Active Sessions); the
    // authoritative amount is always recomputed server-side at checkout.
    $openQuote = $booking->isOpenSession() ? app(\App\Services\RoomPricingService::class)->quoteOpenBooking($booking, now()) : null;
@endphp

@section('content')
    @if (session('success'))
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">{{ session('error') }}</div>
    @endif

    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('app.btn.back_to_bookings') }}</a>
        </div>
        @if (in_array($booking->status, ['pending', 'confirmed']))
            <a href="/bookings/{{ $booking->id }}/edit" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                {{ __('app.booking.edit_booking') }}
            </a>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.booking.bookings') }} #{{ str_pad($booking->id, 4, '0', STR_PAD_LEFT) }}</h1>
                        <p class="text-sm text-gray-500 mt-1">{{ __('app.table.th.created') }} {{ $booking->created_at->format('M d, Y h:i A') }}</p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $booking->statusBadgeClass() }}">
                        {{ $booking->statusLabel() }}
                    </span>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">{{ __('app.booking.room') }}</dt>
                        <dd class="text-gray-900 font-medium mt-1">
                            <a href="/workspaces/{{ $booking->room->workspace->id }}" class="text-blue-600 hover:underline">
                                {{ $booking->room->workspace?->name }}
                            </a>
                            / {{ $booking->room->name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('app.booking.user') }}</dt>
                        <dd class="font-medium mt-1">
                                    <a href="/users/{{ $booking->hotspotUser->id }}" class="text-blue-600 hover:underline">{{ $booking->hotspotUser->name }}</a>
                                    @if ($booking->party_size > 1)
                                        <span class="text-gray-500 text-sm">&middot; {{ __('app.session.party_of', ['count' => $booking->party_size]) }}</span>
                                    @endif
                                    @if ($booking->hotspotUser->phone)
                                        <span class="text-gray-500"> &middot; {{ $booking->hotspotUser->phone }}</span>
                                    @endif
                                    @if ($booking->hotspotUser->email)
                                        <span class="text-gray-500 block">{{ $booking->hotspotUser->email }}</span>
                                    @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('app.booking.date') }}</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $booking->booking_date->format('l, M d, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('app.common.time') }}</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $booking->timeRange() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ __('app.booking.duration') }}</dt>
                        <dd class="text-gray-900 font-medium mt-1">
                            @if ($openQuote)
                                {{ __('app.booking.duration_type.duration_so_far') }}: {{ \App\Support\Duration::label((int) round($openQuote->totalMinutes)) }}
                            @else
                                {{ $booking->total_hours }} {{ __('app.common.hours') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        @if ($booking->pricing_note)
                            <dt class="text-gray-500">{{ __('app.pricing.applied') }}</dt>
                            <dd class="text-gray-900 font-medium mt-1">{{ $booking->pricing_note }}@if ($booking->room_plan_id)<span class="ls-plan-tag">{{ __('app.plans.badge') }}</span>@endif</dd>
                        @else
                            <dt class="text-gray-500">{{ __('app.workspace.price_per_hour') }}</dt>
                            <dd class="text-gray-900 font-medium mt-1">ج.م {{ number_format($booking->price_per_hour, 2) }}</dd>
                        @endif
                    </div>
                    <div class="md:col-span-2">
                        @if ($openQuote)
                            <dt class="text-gray-500">{{ __('app.booking.duration_type.current_amount') }}</dt>
                            <dd class="text-2xl font-bold text-cyan-600 mt-1" id="open-current-amount">ج.م {{ number_format($openQuote->totalPrice, 2) }}</dd>
                        @else
                            <dt class="text-gray-500">{{ __('app.booking.total') }}</dt>
                            <dd class="text-2xl font-bold text-blue-600 mt-1">ج.م {{ number_format($booking->netRoomCharge(), 2) }}</dd>
                            @if ($booking->coupon_id && $booking->discount_total > 0)
                                <dd class="text-xs text-gray-400 mt-0.5">
                                    {{ __('app.coupons.checkout.original_amount', ['amount' => number_format($booking->total_price, 2)]) }}
                                </dd>
                            @endif
                        @endif
                    </div>
                </dl>

                @if ($booking->payment_method === \App\Models\Booking::METHOD_PACKAGE)
                    {{-- Paid with prepaid hours: no cash due; the value is what those hours are worth. --}}
                    <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" id="booking-package-coverage">
                        <x-ui.badge tone="info">{{ __('app.packages.covered_by') }}</x-ui.badge>
                        <span class="font-medium text-gray-900">{{ $booking->memberPackage?->name ?? '—' }} · {{ \App\Support\Duration::label((int) round($booking->total_hours * 60)) }}</span>
                        <span class="text-gray-500">{{ __('app.packages.worth', ['amount' => (app()->getLocale() === 'ar' ? number_format($booking->total_price, 2).' ج.م' : 'EGP '.number_format($booking->total_price, 2))]) }}</span>
                        @if ($booking->hotspotUser)
                            <a href="/users/{{ $booking->hotspot_user_id }}#packages" class="ls-link">{{ $booking->hotspotUser->name }} &rarr;</a>
                        @endif
                    </div>
                @else
                <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                    <div>
                        <span class="text-gray-500">{{ __('app.booking.payment.paid_now') }}</span>
                        <span class="font-medium text-gray-900 ms-1">ج.م {{ number_format($booking->amount_paid, 2) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">{{ __('app.booking.payment.remaining') }}</span>
                        <span class="font-medium text-gray-900 ms-1">ج.م {{ number_format($booking->balanceDue(), 2) }}</span>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium
                        {{ $booking->payment_status === 'paid' ? 'bg-green-100 text-green-700' : ($booking->payment_status === 'partial' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600') }}">
                        {{ $booking->paymentStatusLabel() }}
                    </span>
                </div>
                @endif

                @if ($booking->balanceDue() > 0 && ! in_array($booking->status, ['cancelled', 'no_show']))
                    <form method="POST" action="/bookings/{{ $booking->id }}/payment" class="mt-4 pt-4 border-t border-gray-100 flex items-end gap-2">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('app.booking.payment.record') }}</label>
                            <input type="number" name="amount" min="0.01" max="{{ $booking->balanceDue() }}" step="0.01"
                                   placeholder="{{ number_format($booking->balanceDue(), 2) }}"
                                   class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                            {{ __('app.booking.payment.record_submit') }}
                        </button>
                        @error('amount') <p class="text-xs text-red-600 self-center">{{ $message }}</p> @enderror
                    </form>
                @endif

                @if ($booking->notes)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <dt class="text-sm text-gray-500">{{ __('app.booking.notes') }}</dt>
                        <dd class="text-sm text-gray-900 mt-1">{{ $booking->notes }}</dd>
                    </div>
                @endif
            </div>

            @php
                $canSell = $owner->hasFeature('sales');
                $invoiceEditable = $booking->invoiceIsEditable();
            @endphp
            @if ($canSell)
                @php $sale = $booking->sale; @endphp
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.sales.items_extras') }}</h3>

                    @if (! $invoiceEditable)
                        <p class="text-xs text-gray-400 mb-3">{{ __('app.sales.invoice_not_editable') }}</p>
                    @endif

                    @if ($sale && $sale->items->isNotEmpty())
                        <div class="overflow-x-auto -mx-1 px-1">
                        <table class="w-full text-sm mb-4">
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($sale->items as $item)
                                    <tr>
                                        <td class="py-2 text-gray-900">{{ $item->name }}</td>
                                        <td class="py-2 text-center text-gray-500">
                                            @if ($invoiceEditable)
                                                <form method="POST" action="{{ route('bookings.items.update', [$booking->id, $item->id]) }}" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="quantity" value="{{ $item->quantity - 1 }}">
                                                    <button class="text-gray-400 hover:text-gray-700 px-1" aria-label="{{ __('app.sales.decrease_quantity') }}">&minus;</button>
                                                </form>
                                                &times;{{ $item->quantity }}
                                                <form method="POST" action="{{ route('bookings.items.update', [$booking->id, $item->id]) }}" class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="quantity" value="{{ $item->quantity + 1 }}">
                                                    <button class="text-gray-400 hover:text-gray-700 px-1" aria-label="{{ __('app.sales.increase_quantity') }}">&plus;</button>
                                                </form>
                                            @else
                                                &times;{{ $item->quantity }}
                                            @endif
                                        </td>
                                        <td class="py-2 text-right text-gray-900 font-medium">ج.م {{ number_format($item->line_total, 2) }}</td>
                                        <td class="py-2 text-right w-8">
                                            @if ($invoiceEditable)
                                                <form method="POST" action="{{ route('bookings.items.remove', [$booking->id, $item->id]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-gray-400 hover:text-red-600" title="{{ __('app.common.delete') }}">&times;</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-400 mb-4">{{ __('app.sales.no_items_yet') }}</p>
                    @endif

                    @if ($invoiceEditable)
                        @if ($products->isNotEmpty())
                            <form method="POST" action="{{ route('bookings.items.add', $booking->id) }}" class="flex items-end gap-2 pt-4 border-t border-gray-100">
                                @csrf
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('app.sales.product') }}</label>
                                    <select name="product_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}">{{ $product->name }} — ج.م {{ number_format($product->price, 2) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="w-20">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">{{ __('app.sales.qty') }}</label>
                                    <input type="number" name="quantity" value="1" min="1" max="1000"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                                    {{ __('app.sales.add') }}
                                </button>
                            </form>
                        @else
                            <p class="text-xs text-gray-400 pt-4 border-t border-gray-100">
                                {{ __('app.sales.no_products_hint') }}
                                <a href="{{ route('products.create') }}" class="text-blue-600 hover:underline">{{ __('app.sales.add_product') }}</a>
                            </p>
                        @endif
                    @endif

                    <div class="mt-5 pt-4 border-t border-gray-200 space-y-1 text-sm">
                        <div class="flex justify-between text-gray-500">
                            <span>{{ __('app.sales.room_charge') }}</span>
                            <span>ج.م {{ number_format($booking->netRoomCharge(), 2) }}</span>
                        </div>
                        <div class="flex justify-between text-gray-500">
                            <span>{{ __('app.sales.items') }}</span>
                            <span>ج.م {{ number_format($sale->total ?? 0, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold text-gray-900 pt-1">
                            <span>{{ __('app.sales.grand_total') }}</span>
                            <span class="text-blue-600">ج.م {{ number_format($booking->grandTotal(), 2) }}</span>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.common.actions') }}</h3>

                @if ($booking->status === 'pending')
                    <form method="POST" action="/bookings/{{ $booking->id }}/status" class="space-y-2">
                        @csrf
                        <input type="hidden" name="status" value="confirmed">
                        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                            {{ __('app.btn.confirm_booking') }}
                        </button>
                    </form>
                    <form method="POST" action="/bookings/{{ $booking->id }}/status">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="w-full bg-red-100 text-red-700 px-4 py-2 rounded-lg hover:bg-red-200 transition text-sm font-medium"
                                onclick="return confirm('{{ __('app.booking.cancel_booking') }}')">
                            {{ __('app.btn.cancel_booking') }}
                        </button>
                    </form>
                @elseif ($booking->status === 'confirmed')
                    <form method="POST" action="/bookings/{{ $booking->id }}/status" class="space-y-2">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition text-sm font-medium">
                            {{ __('app.btn.mark_completed') }}
                        </button>
                    </form>
                    <form method="POST" action="/bookings/{{ $booking->id }}/status">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="w-full bg-red-100 text-red-700 px-4 py-2 rounded-lg hover:bg-red-200 transition text-sm font-medium"
                                onclick="return confirm('{{ __('app.booking.cancel_booking') }}')">
                            {{ __('app.btn.cancel_booking') }}
                        </button>
                    </form>
                @elseif ($booking->status === 'completed')
                    <p class="text-sm text-green-600 font-medium text-center">{{ __('app.status.completed') }}</p>
                @elseif ($booking->isOpenSession())
                    <button type="button" class="w-full bg-cyan-600 text-white px-4 py-2 rounded-lg hover:bg-cyan-700 transition text-sm font-medium" data-ls-open="checkout-open-session-modal">
                        {{ __('app.booking.duration_type.checkout') }}
                    </button>
                @endif

                @if ($booking->status === 'checked_in')
                    <p class="text-xs text-gray-500 mt-3">{{ __('app.booking.delete_disabled_checked_in') }}</p>
                @elseif ($booking->isOpenSession())
                    <p class="text-xs text-gray-500 mt-3">{{ __('app.booking.duration_type.delete_disabled_open') }}</p>
                @elseif ($canDeleteBooking)
                    <button type="button" class="w-full mt-3 bg-white border border-red-200 text-red-600 px-4 py-2 rounded-lg hover:bg-red-50 transition text-sm font-medium" data-ls-open="delete-booking-modal">
                        {{ __('app.btn.delete_booking') }}
                    </button>
                @endif
            </div>
        </div>
    </div>

    <x-ui.modal id="delete-booking-modal" :title="__('app.booking.delete_booking')">
        <p>{{ __('app.booking.delete_confirm_intro') }}</p>
        <ul class="list-disc ps-5 text-sm text-gray-600 space-y-1 mt-2">
            <li>{{ __('app.booking.delete_consequence_revenue') }}</li>
            <li>{{ __('app.booking.delete_consequence_package') }}</li>
            <li>{{ __('app.booking.delete_consequence_coupon') }}</li>
            <li>{{ __('app.booking.delete_consequence_stock') }}</li>
        </ul>
        <p class="text-xs text-gray-500 mt-3">{{ __('app.common.cannot_be_undone') }}</p>
        <x-slot:footer>
            <x-ui.button variant="ghost" data-ls-close>{{ __('app.common.cancel') }}</x-ui.button>
            <form method="POST" action="/bookings/{{ $booking->id }}">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger">{{ __('app.btn.delete_booking') }}</x-ui.button>
            </form>
        </x-slot:footer>
    </x-ui.modal>

    @if ($booking->isOpenSession())
        <x-ui.modal id="checkout-open-session-modal" :title="__('app.booking.duration_type.checkout_confirm_title')">
            <div id="checkout-preview-loading" class="text-sm text-gray-500">{{ __('app.session.calculating') }}</div>
            <div id="checkout-preview-content" hidden class="space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">{{ __('app.booking.duration_type.duration_so_far') }}</span><span id="checkout-duration" class="font-medium"></span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('app.sales.room_charge') }}</span><span id="checkout-room-charge" class="font-medium"></span></div>
                <div class="flex justify-between"><span class="text-gray-500">{{ __('app.sales.items') }}</span><span id="checkout-items-total" class="font-medium"></span></div>
                <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-100"><span>{{ __('app.sales.grand_total') }}</span><span id="checkout-grand-total" class="text-cyan-600"></span></div>
            </div>
            <x-slot:footer>
                <x-ui.button variant="ghost" data-ls-close>{{ __('app.common.cancel') }}</x-ui.button>
                <x-ui.button type="button" variant="primary" id="checkout-confirm-btn" disabled>{{ __('app.booking.duration_type.checkout') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <script>
        (function () {
            const modal = document.getElementById('checkout-open-session-modal');
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const confirmBtn = document.getElementById('checkout-confirm-btn');

            function loadPreview() {
                document.getElementById('checkout-preview-content').hidden = true;
                document.getElementById('checkout-preview-loading').hidden = false;
                confirmBtn.disabled = true;
                fetch('/bookings/{{ $booking->id }}/close-preview', { headers: { 'Accept': 'application/json' } })
                    .then(r => r.json())
                    .then(data => {
                        document.getElementById('checkout-duration').textContent = data.duration;
                        document.getElementById('checkout-room-charge').textContent = 'ج.م ' + data.total_price;
                        document.getElementById('checkout-items-total').textContent = 'ج.م ' + data.items_total;
                        document.getElementById('checkout-grand-total').textContent = 'ج.م ' + data.grand_total;
                        document.getElementById('checkout-preview-loading').hidden = true;
                        document.getElementById('checkout-preview-content').hidden = false;
                        confirmBtn.disabled = false;
                    });
            }

            modal.addEventListener('ls:open', loadPreview);

            confirmBtn.addEventListener('click', function () {
                LS.busy(confirmBtn, true);
                fetch('/bookings/{{ $booking->id }}/close', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) { LS.busy(confirmBtn, false); LS.toast(data.message, { tone: 'danger' }); return; }
                    LS.reloadWithToast(data.message);
                })
                .catch(() => { LS.busy(confirmBtn, false); });
            });
        })();
        </script>
    @endif
@endsection