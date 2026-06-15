<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Expired - LinkSpace</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-50 to-red-50 flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-lg p-8 text-center">
        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-gray-900 mb-2">Subscription Expired</h1>

        <div class="bg-gray-50 rounded-lg p-4 mb-6 text-left">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Business</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->business_name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Owner</dt>
                    <dd class="text-gray-900">{{ $owner->name }}</dd>
                </div>
                @if ($owner->subscription_expires_at)
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Expired On</dt>
                        <dd class="text-red-600 font-medium">{{ $owner->subscription_expires_at->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Days Ago</dt>
                        <dd class="text-gray-900">{{ $owner->daysUntilExpiry() === 0 ? now()->diffInDays($owner->subscription_expires_at) : 0 }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <p class="text-gray-600 text-sm mb-6">
            Your subscription has expired. Please contact your LinkSpace administrator to renew your subscription.
        </p>

        <form method="POST" action="/logout">
            @csrf
            <button type="submit" class="bg-gray-800 text-white px-6 py-2.5 rounded-lg hover:bg-gray-700 transition font-medium text-sm w-full">
                Logout
            </button>
        </form>
    </div>
</body>
</html>
