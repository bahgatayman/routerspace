<?php

use App\Http\Controllers\Api\V1\PublicWorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer API — /api/v1
|--------------------------------------------------------------------------
|
| Stateless JSON API shared by the Link Space website and mobile app.
| Only public, read-only endpoints exist so far: no sessions, no cookies, no
| owner/admin data. Owner and Super Admin features stay in routes/web.php.
|
*/

Route::prefix('v1')->middleware(['api.locale', 'throttle:public-api'])->group(function () {
    Route::get('/workspaces', [PublicWorkspaceController::class, 'index'])->name('api.v1.workspaces.index');
    Route::get('/workspaces/{slug}', [PublicWorkspaceController::class, 'show'])->name('api.v1.workspaces.show');
});
