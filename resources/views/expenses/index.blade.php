@extends('layouts.app')

@section('page-title', __('app.expenses.title'))

@section('content')
    @php
        $currency = app()->getLocale() === 'ar' ? 'ج.م' : 'EGP';
        $top = $byCategory[0] ?? null;
        $categoryUrl = fn ($value) => request()->fullUrlWithQuery(['category' => $value, 'page' => null]);
        $addErrors = $errors->hasAny(['amount', 'expense_category_id', 'expense_date', 'note']);
    @endphp

    <x-ui.page-header :title="__('app.expenses.title')" :subtitle="$periodLabel" :count="$entryCount">
        <x-slot:actions>
            @if ($canManageCategories)
                <x-ui.button icon="tag" data-ls-open="manage-categories">{{ __('app.expenses.manage_categories') }}</x-ui.button>
            @endif
            @if ($canCreate)
                <x-ui.button variant="primary" icon="plus" data-ls-open="add-expense">{{ __('app.expenses.add_expense') }}</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    {{-- Period: three presets, plus a custom range that only opens when asked for. --}}
    <div class="ls-exp-period">
        <nav class="ls-chips is-scroll" aria-label="{{ __('app.financials.overview') }}">
            @foreach (['today' => 'app.financials.period_today', 'this_week' => 'app.financials.period_week', 'this_month' => 'app.financials.period_month'] as $key => $labelKey)
                <a href="{{ request()->fullUrlWithQuery(['period' => $key, 'start' => null, 'end' => null, 'page' => null]) }}"
                   class="ls-chip {{ $periodKey === $key ? 'is-active' : '' }}" @if ($periodKey === $key) aria-current="page" @endif>{{ __($labelKey) }}</a>
            @endforeach
            <button type="button" class="ls-chip {{ $periodKey === 'custom' ? 'is-active' : '' }}" aria-expanded="{{ $periodKey === 'custom' ? 'true' : 'false' }}" aria-controls="exp-range"
                    onclick="const f = document.getElementById('exp-range'); f.hidden = !f.hidden; this.setAttribute('aria-expanded', String(!f.hidden)); if (!f.hidden) f.querySelector('input').focus();">
                <x-ui.icon name="calendar" />{{ __('app.expenses.custom_range') }}
            </button>
        </nav>
        <form method="GET" id="exp-range" class="ls-exp-range" @unless ($periodKey === 'custom') hidden @endunless>
            <input type="hidden" name="period" value="custom">
            @if ($activeCategory !== null)<input type="hidden" name="category" value="{{ $activeCategory }}">@endif
            <input type="date" name="start" value="{{ $customStart ?? $period->startDate() }}" class="ls-input" aria-label="{{ __('app.expenses.date') }}" required>
            <span aria-hidden="true">–</span>
            <input type="date" name="end" value="{{ $customEnd ?? $period->endDate() }}" class="ls-input" aria-label="{{ __('app.expenses.date') }}" required>
            <x-ui.button type="submit" size="sm">{{ __('app.financials.apply') }}</x-ui.button>
        </form>
    </div>

    <div class="ls-strip">
        <div>
            <span class="ls-strip-label">{{ __('app.expenses.total_expenses') }}</span>
            <span class="ls-strip-value is-brand"><x-ui.money :amount="$totalExpenses" /></span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.expenses.entries') }}</span>
            <span class="ls-strip-value">{{ number_format($entryCount) }}</span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.expenses.top_category') }}</span>
            @if ($top)
                <span class="ls-strip-value ls-trunc" title="{{ $top['name'] }}">{{ $top['name'] }}</span>
                <span class="ls-strip-meta"><x-ui.money :amount="$top['amount']" /></span>
            @else
                <span class="ls-strip-value">—</span>
            @endif
        </div>
    </div>

    <div class="ls-exp-layout">
        {{-- Breakdown doubles as the category filter; first in the DOM so it sits above the list on phones. --}}
        @if (! empty($byCategory))
            <aside class="ls-card ls-exp-side" aria-labelledby="exp-by-cat">
                <div class="ls-card-head"><h2 class="ls-card-title" id="exp-by-cat">{{ __('app.expenses.by_category') }}</h2></div>
                <div class="ls-card-body">
                    <a href="{{ $categoryUrl(null) }}" class="ls-exp-cat-row {{ $activeCategory === null ? 'is-active' : '' }}" @if ($activeCategory === null) aria-current="page" @endif>
                        <span class="ls-exp-cat-name">{{ __('app.expenses.all_categories') }}</span>
                        <span class="ls-exp-cat-amount"><x-ui.money :amount="$totalExpenses" /></span>
                    </a>
                    @foreach ($byCategory as $row)
                        @php
                            $value = $row['category_id'] ?? 'none';
                            $on = $activeCategory === $value;
                            $pct = $totalExpenses > 0 ? round($row['amount'] / $totalExpenses * 100) : 0;
                        @endphp
                        <a href="{{ $categoryUrl($value) }}" class="ls-exp-cat-row {{ $on ? 'is-active' : '' }}" @if ($on) aria-current="page" @endif>
                            <span class="ls-exp-cat-name ls-trunc" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                            <span class="ls-exp-cat-amount"><x-ui.money :amount="$row['amount']" /></span>
                            <span class="ls-meter" aria-hidden="true"><i style="width: {{ max(2, $pct) }}%"></i></span>
                            <span class="ls-exp-cat-share">{{ __('app.expenses.share_of_total', ['percent' => $pct]) }}</span>
                        </a>
                    @endforeach
                </div>
            </aside>
        @endif

        <section class="ls-card ls-exp-main" aria-label="{{ __('app.expenses.title') }}">
            @if ($expenses->isEmpty())
                @if ($activeCategory !== null)
                    <x-ui.empty-state illustration="search" :title="__('app.expenses.filtered_empty')">
                        <x-ui.button :href="$categoryUrl(null)">{{ __('app.expenses.show_all') }}</x-ui.button>
                    </x-ui.empty-state>
                @else
                    <x-ui.empty-state illustration="quiet" :title="__('app.expenses.empty_title')" :text="__('app.expenses.empty_text')">
                        @if ($canCreate)
                            <x-ui.button variant="primary" icon="plus" data-ls-open="add-expense">{{ __('app.expenses.add_expense') }}</x-ui.button>
                        @endif
                    </x-ui.empty-state>
                @endif
            @else
                <div class="ls-exp-toolbar">
                    <x-ui.search group="expenses" :placeholder="__('app.expenses.search')" width="100%" />
                </div>
                <ul class="ls-exp-list">
                    @foreach ($expenses as $expense)
                        @php $categoryName = $expense->category?->name ?? __('app.expenses.uncategorized'); @endphp
                        <li class="ls-exp-row" data-ls-item="expenses" data-ls-text="{{ $expense->note }} {{ $categoryName }} {{ $expense->amount }}">
                            <span class="ls-exp-date" aria-label="{{ $expense->expense_date->translatedFormat('j F Y') }}">
                                <b>{{ $expense->expense_date->format('j') }}</b>
                                <span>{{ $expense->expense_date->translatedFormat('M') }}</span>
                            </span>
                            <span class="ls-exp-body">
                                <span class="ls-exp-title ls-trunc" title="{{ $expense->note ?? $categoryName }}">{{ $expense->note ?? $categoryName }}</span>
                                <span class="ls-exp-meta">
                                    @if ($expense->note)<span class="ls-exp-tag {{ $expense->category ? '' : 'is-none' }}">{{ $categoryName }}</span>@endif
                                    <span>{{ $expense->expense_date->translatedFormat('l') }}</span>
                                </span>
                            </span>
                            <span class="ls-exp-amount"><x-ui.money :amount="$expense->amount" /></span>
                            @if ($canEdit || $canDelete)
                                <span class="ls-exp-actions">
                                    @if ($canEdit)
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :icon-only="true" :href="route('expenses.edit', $expense->id)"
                                            :aria-label="__('app.expenses.edit').': '.($expense->note ?? $categoryName)" :title="__('app.expenses.edit')" />
                                    @endif
                                    @if ($canDelete)
                                        <form method="POST" action="{{ route('expenses.destroy', $expense->id) }}" onsubmit="return confirm(@js(__('app.expenses.delete_confirm')))">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button type="submit" size="sm" variant="danger-quiet" icon="trash" :icon-only="true"
                                                :aria-label="__('app.expenses.delete').': '.($expense->note ?? $categoryName)" :title="__('app.expenses.delete')" />
                                        </form>
                                    @endif
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div data-ls-empty="expenses" hidden>
                    <x-ui.empty-state illustration="search" :title="__('app.expenses.no_search_match')" />
                </div>
                @if ($expenses->hasPages())
                    <div class="ls-exp-pager">{{ $expenses->links() }}</div>
                @endif
            @endif
        </section>
    </div>

    @if ($canCreate)
        <x-ui.modal id="add-expense" :title="__('app.expenses.add_expense')">
            <form id="add-expense-form" method="POST" action="{{ route('expenses.store') }}" class="ls-exp-form">
                @csrf
                <div class="ls-field ls-exp-form-wide">
                    <label class="ls-label" for="exp-amount">{{ __('app.expenses.amount') }} <span class="ls-req" aria-hidden="true">*</span></label>
                    <div class="ls-input-affix"><span aria-hidden="true">{{ $currency }}</span>
                        <input type="number" step="0.01" min="0.01" id="exp-amount" name="amount" value="{{ old('amount') }}" placeholder="0.00" required inputmode="decimal" class="ls-input">
                    </div>
                    @error('amount') <p class="ls-error">{{ $message }}</p> @enderror
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="exp-category">{{ __('app.expenses.category') }}</label>
                    <select id="exp-category" name="expense_category_id" class="ls-select">
                        <option value="">{{ __('app.expenses.uncategorized') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('expense_category_id', is_int($activeCategory) ? $activeCategory : null) == $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                    @error('expense_category_id') <p class="ls-error">{{ $message }}</p> @enderror
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="exp-date">{{ __('app.expenses.date') }} <span class="ls-req" aria-hidden="true">*</span></label>
                    <input type="date" id="exp-date" name="expense_date" value="{{ old('expense_date', now()->toDateString()) }}" required class="ls-input">
                    @error('expense_date') <p class="ls-error">{{ $message }}</p> @enderror
                </div>
                <div class="ls-field ls-exp-form-wide">
                    <label class="ls-label" for="exp-note">{{ __('app.expenses.note_optional') }}</label>
                    <input type="text" id="exp-note" name="note" value="{{ old('note') }}" maxlength="500" class="ls-input">
                    @error('note') <p class="ls-error">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <div class="ls-push">
                    <button type="button" class="ls-btn ls-btn--secondary" data-ls-close>{{ __('app.expenses.cancel') }}</button>
                    <button type="submit" form="add-expense-form" class="ls-btn ls-btn--primary">{{ __('app.expenses.save_expense') }}</button>
                </div>
            </x-slot:footer>
        </x-ui.modal>
        @if ($addErrors)
            <script>document.addEventListener('DOMContentLoaded', () => window.LS && LS.open('add-expense'));</script>
        @endif
    @endif

    @if ($canManageCategories)
        <x-ui.modal id="manage-categories" :title="__('app.expenses.manage_categories')" :subtitle="__('app.expenses.categories_hint')">
            <form method="POST" action="{{ route('expense-categories.store') }}" class="ls-exp-cat-add">
                @csrf
                <input type="text" name="name" class="ls-input" placeholder="{{ __('app.expenses.category_name') }}" aria-label="{{ __('app.expenses.category_name') }}" required maxlength="100">
                <x-ui.button type="submit" icon="plus">{{ __('app.expenses.add_category') }}</x-ui.button>
            </form>
            @error('name') <p class="ls-error">{{ $message }}</p> @enderror

            @if ($categories->isEmpty())
                <p class="ls-hint" style="margin-top: var(--space-4)">{{ __('app.expenses.no_categories') }}</p>
            @else
                <ul class="ls-exp-cat-list">
                    @foreach ($categories as $category)
                        <li>
                            <form method="POST" action="{{ route('expense-categories.update', $category->id) }}" class="ls-exp-cat-edit">
                                @csrf
                                @method('PUT')
                                <input type="text" name="name" value="{{ $category->name }}" maxlength="100" required class="ls-input" aria-label="{{ __('app.expenses.category_name') }}">
                                <x-ui.button type="submit" size="sm" variant="ghost">{{ __('app.expenses.save') }}</x-ui.button>
                            </form>
                            <span class="ls-exp-cat-count">{{ trans_choice('app.expenses.expense_count', $category->expenses_count, ['count' => $category->expenses_count]) }}</span>
                            @if ($category->expenses_count > 0)
                                <x-ui.button size="sm" variant="ghost" icon="trash" :icon-only="true" disabled
                                    :aria-label="__('app.expenses.category_delete_error', ['count' => $category->expenses_count])"
                                    :title="__('app.expenses.category_delete_error', ['count' => $category->expenses_count])" />
                            @else
                                <form method="POST" action="{{ route('expense-categories.destroy', $category->id) }}" onsubmit="return confirm(@js(__('app.expenses.delete_confirm')))">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" size="sm" variant="danger-quiet" icon="trash" :icon-only="true"
                                        :aria-label="__('app.expenses.delete').': '.$category->name" :title="__('app.expenses.delete')" />
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.modal>
        @if ($errors->has('name'))
            <script>document.addEventListener('DOMContentLoaded', () => window.LS && LS.open('manage-categories'));</script>
        @endif
    @endif
@endsection
