<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DemoRequestController extends Controller
{
    public function send(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|max:255',
            'phone'   => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:2000',
        ]);

        $body = "New Demo Request\n\n"
            . "Name: {$validated['name']}\n"
            . "Email: {$validated['email']}\n"
            . "Phone: " . ($validated['phone'] ?? 'N/A') . "\n"
            . "Company: " . ($validated['company'] ?? 'N/A') . "\n"
            . "Message: " . ($validated['message'] ?? 'N/A');

        Mail::raw($body, function ($message) use ($validated) {
            $message->to('bahgatayman10@gmail.com')
                ->subject('New Demo Request - ' . $validated['name'])
                ->replyTo($validated['email'], $validated['name']);
        });

        return back()->with('success', 'Thank you! We received your demo request and will get back to you shortly.');
    }
}
