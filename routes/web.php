<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\ClaudeExtractionController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\TransactionController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public by design (everything else requires login)
|--------------------------------------------------------------------------
| - login form / submit (submit is throttled)
| - the Claude routine callback: one-time bearer token, status gate, CSRF-exempt, throttled
| - the Claude routine image fetch: signed + expiring URL
| - /up (framework health check, returns no data)
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1')->name('login.store');
});

Route::prefix('claude-extractions')->name('claude.')->group(function () {
    Route::post('/{uuid}/result', [ClaudeExtractionController::class, 'callback'])->name('callback')
        ->whereUuid('uuid')->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:30,1');
    Route::get('/{uuid}/image/{index}', [ClaudeExtractionController::class, 'image'])->name('image')
        ->whereUuid('uuid')->whereNumber('index')->middleware('signed');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
| auth.session: a password change (e.g. an admin reset) invalidates that user's other sessions,
| whichever session driver is in use.
*/
Route::middleware(['auth', AuthenticateSession::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('account.ok')->group(function () {
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.change');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('/', function () {
            $user = auth()->user();

            return redirect()->route(
                $user->canAny(['remittances.view-own', 'remittances.view-all']) ? 'transactions.index' : 'files.index'
            );
        })->name('home');

        // ---- Transactions: a user's own uploads (admins may widen to everyone) ----
        Route::middleware('permission:remittances.view-own|remittances.view-all')->group(function () {
            Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
            Route::get('/transactions/data', [TransactionController::class, 'data'])
                ->middleware('throttle:120,1')->name('transactions.data');
            Route::get('/transactions/{ref}', [TransactionController::class, 'show'])
                ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->middleware('throttle:240,1')->name('transactions.show');
            Route::get('/transactions/{ref}/status', [TransactionController::class, 'status'])
                ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->middleware('throttle:240,1')->name('transactions.status');
        });

        Route::post('/transactions', [TransactionController::class, 'store'])
            ->middleware(['permission:remittances.upload', 'throttle:20,1'])->name('transactions.store');
        Route::put('/transactions/{ref}', [TransactionController::class, 'update'])
            ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->middleware(['permission:remittances.confirm', 'throttle:60,1'])->name('transactions.update');
        Route::delete('/transactions/{ref}', [TransactionController::class, 'destroy'])
            ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->middleware(['permission:remittances.delete', 'throttle:30,1'])->name('transactions.destroy');
        Route::put('/transactions/{ref}/files/{position}/visibility', [TransactionController::class, 'visibility'])
            ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->whereNumber('position')
            ->middleware(['permission:files.set-visibility', 'throttle:60,1'])->name('transactions.visibility');

        // ---- Files ----
        Route::get('/files', [FileController::class, 'index'])
            ->middleware('permission:files.view-public')->name('files.index');
        Route::get('/files/data', [FileController::class, 'data'])
            ->middleware(['permission:files.view-public', 'throttle:120,1'])->name('files.data');
        Route::get('/files/{ref}/{position}', [FileController::class, 'image'])
            ->where('ref', 'RA-[0-9]{6}-[A-Z0-9]{5}')->whereNumber('position')
            ->middleware(['permission:files.view-public|remittances.view-own|remittances.view-all', 'throttle:600,1'])
            ->name('files.image');

        // ---- Administration ----
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
