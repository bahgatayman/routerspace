<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function expired()
    {
        $owner = auth('owner')->user();

        if (!$owner) {
            return redirect('/login');
        }

        if ($owner->isSubscriptionActive()) {
            return redirect('/dashboard');
        }

        return view('subscription.expired', compact('owner'));
    }
}
