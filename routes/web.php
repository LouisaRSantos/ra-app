<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ClaudeExtractionController;
use App\Http\Controllers\ExtractionController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public by design (everything else requires login)
|--------------------------------------------------------------------------
| - login form / submit
| - the Claude routine callback: bearer-token protected, one-time, CSRF-exempt, throttled
| - the Claude routine image fetch: signed + expiring URL
| - /up (framework health check, returns no data)
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
});

Route::prefix('claude-extractions')->name('claude.')->group(function () {
    Route::post('/{uuid}/result', [ClaudeExtractionController::class, 'callback'])->name('callback')
        ->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:30,1');
    Route::get('/{uuid}/image/{index}', [ClaudeExtractionController::class, 'image'])->name('image')
        ->whereNumber('index')->middleware('signed');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
// auth.session: a password change (e.g. an admin reset) invalidates that user's other sessions,
// whichever session driver is in use.
Route::middleware(['auth', AuthenticateSession::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('account.ok')->group(function () {
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.change');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('/', function () {
            $user = auth()->user();

            return redirect()->route(match (true) {
                $user->can('users.manage') => 'admin.users.index',
                $user->can('remittances.upload') => 'claude.index',
                default => 'files.index',
            });
        })->name('home');

        // Public files for the read-only User role (list is built in Phase 4).
        Route::get('/files', fn () => view('files.index'))
            ->middleware('permission:files.view-public')->name('files.index');

        // Upload + extraction. Ownership is enforced per record inside the controllers.
        Route::middleware('permission:remittances.upload')->group(function () {
            Route::get('/extractions', [ExtractionController::class, 'index'])->name('extractions.index');
            Route::post('/extractions', [ExtractionController::class, 'store'])->name('extractions.store');
            Route::get('/claude-extractions', [ClaudeExtractionController::class, 'index'])->name('claude.index');
            Route::post('/claude-extractions', [ClaudeExtractionController::class, 'store'])->name('claude.store');
        });

        Route::middleware('permission:remittances.view-own|remittances.view-all')->group(function () {
            Route::get('/extractions/{uuid}/status', [ExtractionController::class, 'status'])->name('extractions.status');
            Route::get('/extractions/{uuid}/image/{index}', [ExtractionController::class, 'image'])->name('extractions.image')->whereNumber('index');
            Route::get('/extractions/{uuid}', [ExtractionController::class, 'show'])->name('extractions.show');
        });

        Route::put('/extractions/{uuid}', [ExtractionController::class, 'update'])
            ->middleware('permission:remittances.confirm')->name('extractions.update');

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('permission:users.manage')->group(function () {
                Route::get('/users', [UserController::class, 'index'])->name('users.index');
                Route::get('/users/data', [UserController::class, 'data'])->name('users.data');
                Route::post('/users', [UserController::class, 'store'])->name('users.store');
                Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password');
            });

            Route::middleware('permission:roles.manage')->group(function () {
                Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
                Route::get('/roles/data', [RoleController::class, 'data'])->name('roles.data');
                Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            });
        });
    });
});
