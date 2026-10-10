@extends('layouts.app')

@section('page-title', __('app.user.add_new_user'))

@section('content')
    {{-- Full-page fallback (direct link / new tab). The Users page opens the same form as a pop-up. --}}
    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
            @foreach ($errors->all() as $error)
                <p class="text-sm">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="max-w-lg mx-auto">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <h2 class="text-xl font-semibold text-gray-900 mb-6">{{ __('app.user.add_new_user') }}</h2>
            @include('users._member-form', ['inline' => true, 'formId' => 'create-member-form'])
        </div>
    </div>
@endsection
