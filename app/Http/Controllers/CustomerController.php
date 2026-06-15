<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $ownerId = auth('owner')->id();
        $search  = $request->get('search');

        $customers = Customer::where('owner_id', $ownerId)
            ->withCount('bookings')
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                       ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15);

        return view('customers.index', compact('customers', 'search'));
    }

    public function create(): View
    {
        return view('customers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $validated['owner_id'] = auth('owner')->id();

        Customer::create($validated);

        return redirect('/customers')->with('success', 'Customer created successfully.');
    }

    public function show($id): View
    {
        $customer = Customer::where('owner_id', auth('owner')->id())
            ->findOrFail($id);

        $bookings = $customer->bookings()
            ->with(['room.workspace'])
            ->latest()
            ->take(10)
            ->get();

        return view('customers.show', compact('customer', 'bookings'));
    }

    public function edit($id): View
    {
        $customer = Customer::where('owner_id', auth('owner')->id())
            ->findOrFail($id);

        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, $id): RedirectResponse
    {
        $customer = Customer::where('owner_id', auth('owner')->id())
            ->findOrFail($id);

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        $customer->update($validated);

        return redirect('/customers')->with('success', 'Customer updated successfully.');
    }

    public function destroy($id): RedirectResponse
    {
        $customer = Customer::where('owner_id', auth('owner')->id())
            ->findOrFail($id);

        $customer->delete();

        return redirect('/customers')->with('success', 'Customer deleted successfully.');
    }
}
