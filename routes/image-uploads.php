<?php

use App\Http\Controllers\Api\ImageUploadController;
use App\Http\Middleware\Api\V1\EnsureAccountActive;
use Illuminate\Support\Facades\Route;

// Included by both API surfaces; the native prefix/ability middleware is inherited.
Route::middleware(['auth:sanctum', EnsureAccountActive::class])->group(function (): void {
    Route::put('/image-uploads/{imageUpload}', [ImageUploadController::class, 'update'])
        ->whereUuid('imageUpload')->middleware('throttle:10,1,image-upload-put:');
    Route::get('/image-uploads/{imageUpload}', [ImageUploadController::class, 'show'])
        ->whereUuid('imageUpload')->middleware('throttle:120,1,image-upload-get:');
    Route::delete('/image-uploads/{imageUpload}', [ImageUploadController::class, 'destroy'])
        ->whereUuid('imageUpload')->middleware('throttle:30,1,image-upload-delete:');
});
