@extends('layouts.app')

@section('page-title', 'New Booking')

@section('content')
    <div class="mb-6">
        <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Bookings</a>
    </div>

    <h1 class="text-2xl font-bold text-gray-900 mb-6">New Booking</h1>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2">
            <form method="POST" action="/bookings" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                        <select name="customer_id" id="customer_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <option value="">Select a customer</option>
                            @foreach ($customers as $customer)
                                <option value="{{ $customer->id }}" {{ $selectedCustomer == $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }}
                                </option>
                            @endforeach
                        </select>
                        <a href="/customers/create" class="text-xs text-blue-600 hover:underline mt-1 inline-block">+ Add New Customer</a>
                        @error('customer_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Room</label>
                        <select name="room_id" id="room_id" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <option value="">Select a room</option>
                            @php $grouped = $rooms->groupBy(fn($r) => $r->workspace?->name ?? 'Unnamed'); @endphp
                            @foreach ($grouped as $workspaceName => $roomsInGroup)
                                <optgroup label="{{ $workspaceName }}">
                                    @foreach ($roomsInGroup as $room)
                                        <option value="{{ $room->id }}" data-price="{{ $room->price_per_hour }}">
                                            {{ $room->name }} ({{ number_format($room->price_per_hour, 2) }} EGP/hr)
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('room_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" name="booking_date" id="booking_date"
                               min="{{ now()->format('Y-m-d') }}" value="{{ old('booking_date') }}" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                        @error('booking_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Start Time</label>
                        <select name="start_time" id="start_time" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <option value="">Select start time</option>
                            @foreach ($timeSlots as $value => $label)
                                <option value="{{ $value }}" {{ old('start_time') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('start_time') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">End Time</label>
                        <select name="end_time" id="end_time" required
                                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                            <option value="">Select end time</option>
                            @foreach ($timeSlots as $value => $label)
                                <option value="{{ $value }}" {{ old('end_time') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('end_time') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <textarea name="notes" rows="2" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm" placeholder="Any special requests...">{{ old('notes') }}</textarea>
                    @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        Confirm Booking
                    </button>
                    <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </form>
        </div>

        <div class="bg-blue-50 rounded-xl p-6 sticky top-6 h-fit">
            <h3 class="font-semibold text-gray-900 mb-4">Booking Summary</h3>
            <div id="preview-loading" class="hidden text-sm text-gray-500">Checking availability...</div>
            <div id="preview-content">
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">Duration</span>
                        <span id="preview-hours" class="font-medium">--</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-600">Rate</span>
                        <span id="preview-rate" class="font-medium">--</span>
                    </div>
                    <div class="border-t pt-2 flex justify-between">
                        <span class="font-semibold">Total</span>
                        <span id="preview-total" class="font-bold text-blue-600 text-lg">--</span>
                    </div>
                </div>
                <div id="availability-status" class="mt-4 text-sm rounded-lg p-3 hidden"></div>
            </div>
        </div>
    </div>

    <script>
    const roomSelect   = document.getElementById('room_id');
    const dateInput    = document.getElementById('booking_date');
    const startSelect  = document.getElementById('start_time');
    const endSelect    = document.getElementById('end_time');

    function checkAvailability() {
        const roomId    = roomSelect.value;
        const date      = dateInput.value;
        const startTime = startSelect.value;
        const endTime   = endSelect.value;

        if (!roomId || !date || !startTime || !endTime) return;

        document.getElementById('preview-loading').classList.remove('hidden');
        document.getElementById('availability-status').classList.add('hidden');

        fetch(`/bookings/check-availability?room_id=${roomId}&booking_date=${date}&start_time=${startTime}&end_time=${endTime}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById('preview-loading').classList.add('hidden');

                const statusDiv = document.getElementById('availability-status');
                statusDiv.classList.remove('hidden');

                if (data.available) {
                    document.getElementById('preview-hours').textContent = data.total_hours + ' hrs';
                    document.getElementById('preview-rate').textContent  = data.price_per_hour + ' / hr';
                    document.getElementById('preview-total').textContent = data.total_price + ' EGP';
                    statusDiv.className = 'mt-4 text-sm rounded-lg p-3 bg-green-100 text-green-700';
                    statusDiv.textContent = '\u2713 Room is available';
                } else {
                    document.getElementById('preview-hours').textContent = '--';
                    document.getElementById('preview-rate').textContent  = '--';
                    document.getElementById('preview-total').textContent = '--';
                    statusDiv.className = 'mt-4 text-sm rounded-lg p-3 bg-red-100 text-red-700';
                    statusDiv.textContent = '\u2717 Room is already booked for this time';
                }
            })
            .catch(() => {
                document.getElementById('preview-loading').classList.add('hidden');
            });
    }

    [roomSelect, dateInput, startSelect, endSelect].forEach(el => {
        el.addEventListener('change', checkAvailability);
    });
    </script>
@endsection
