@extends('layouts.app')

@section('page-title', __('app.availability.title'))

@section('content')
    {{-- Full-page version (direct link / no JavaScript). The Bookings list and the
         Calendar open the same panel in a pop-up instead (bookings._availability-modal). --}}
    <x-ui.page-header :title="__('app.availability.title')" :subtitle="__('app.availability.subtitle')" />

    @include('bookings._availability-panel')
@endsection
