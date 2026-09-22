<?php

use Digizu\PageEditor\Http\Controllers\PageEditorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:30,1'])->post('_editor/{page}', PageEditorController::class)->name('page-editor.save');

Route::get('_editor/assets/{asset}', function (string $asset) {
    abort_unless(in_array($asset, ['editor.js', 'editor.css', 'alpine.js', 'InterVariable.woff2'], true), 404);
    return response()->file(__DIR__.'/../dist/'.$asset, [
        'Content-Type' => match ($asset) { 'editor.css' => 'text/css', 'InterVariable.woff2' => 'font/woff2', default => 'application/javascript' },
        'Cache-Control' => 'public, max-age=3600',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('page-editor.asset');

Route::middleware(['web', 'throttle:20,1'])->post('_editor/{page}/images', \Digizu\PageEditor\Http\Controllers\ImageUploadController::class)->name('page-editor.image-upload');

Route::middleware(['web', 'throttle:60,1'])->get('_editor/{page}/images', \Digizu\PageEditor\Http\Controllers\ImageLibraryController::class)->name('page-editor.image-library');

Route::middleware(['web', 'throttle:5,1'])->post('_editor/local/reset', \Digizu\PageEditor\Http\Controllers\ResetCmsController::class)->name('page-editor.reset');

Route::middleware(['web', 'throttle:5,1'])->post('_editor/transfer/export', [\Digizu\PageEditor\Http\Controllers\ContentTransferController::class, 'export'])->name('page-editor.export');
Route::middleware(['web', 'throttle:5,1'])->post('_editor/transfer/import', [\Digizu\PageEditor\Http\Controllers\ContentTransferController::class, 'import'])->name('page-editor.import');
