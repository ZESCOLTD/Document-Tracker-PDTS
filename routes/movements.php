<?php

use App\Http\Controllers\DocumentScanController;
use App\Livewire\Movements\Custody;
use App\Livewire\Movements\History;
use App\Livewire\Movements\Pending;
use App\Livewire\Movements\Track;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('movements')
    ->name('movements.')
    ->group(function () {
        Route::get('track', Track::class)->middleware('can:documents.register')->name('track');
        Route::get('pending', Pending::class)->middleware('can:documents.acknowledge')->name('pending');
        Route::get('custody', Custody::class)->middleware('can:documents.forward')->name('custody');

        // Access to these two depends on the document, so they check it themselves.
        Route::get('documents/{document}/history', History::class)->name('history');
        Route::get('documents/{document}/scan', DocumentScanController::class)->name('scan');
    });
