{{--
    "Check availability" as a pop-up on the Bookings list and the Calendar. The
    trigger keeps href="/bookings/availability" as the no-JS fallback and adds
    data-ls-open="availability-modal". Expects $avRooms, $avTimeSlots.
--}}
<x-ui.modal id="availability-modal" :title="__('app.availability.title')" :subtitle="__('app.availability.subtitle')" size="wide" class="ls-av-modal">
    @include('bookings._availability-panel', ['inModal' => true])
</x-ui.modal>
