<?php

use App\Http\Controllers\ClaudeExtractionController;
use App\Http\Controllers\ExtractionController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/extractions', [ExtractionController::class, 'index'])->name('extractions.index');
Route::post('/extractions', [ExtractionController::class, 'store'])->name('extractions.store');
Route::get('/extractions/{uuid}/status', [ExtractionController::class, 'status'])->name('extractions.status');
Route::get('/extractions/{uuid}/image/{index}', [ExtractionController::class, 'image'])->name('extractions.image')->whereNumber('index');
Route::get('/extractions/{uuid}', [ExtractionController::class, 'show'])->name('extractions.show');
Route::put('/extractions/{uuid}', [ExtractionController::class, 'update'])->name('extractions.update');

// Claude-routine engine (separate from the Groq flow above; status/show/update are shared).
Route::prefix('claude-extractions')->name('claude.')->group(function () {
    Route::get('/', [ClaudeExtractionController::class, 'index'])->name('index');
    Route::post('/', [ClaudeExtractionController::class, 'store'])->name('store');
    Route::post('/{uuid}/result', [ClaudeExtractionController::class, 'callback'])->name('callback')
        ->withoutMiddleware(ValidateCsrfToken::class)->middleware('throttle:30,1');
    Route::get('/{uuid}/image/{index}', [ClaudeExtractionController::class, 'image'])->name('image')
        ->whereNumber('index')->middleware('signed');
});
