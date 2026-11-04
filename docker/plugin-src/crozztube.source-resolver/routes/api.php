<?php

use CrzSourceResolver\ResolverController;
use Illuminate\Support\Facades\Route;

Route::get('/status/{videoId}', [ResolverController::class, 'status'])
    ->whereNumber('videoId')
    ->middleware('throttle:120,1');

Route::post('/refresh/{videoId}', [ResolverController::class, 'refresh'])
    ->whereNumber('videoId')
    ->middleware('throttle:20,1');

Route::get('/manifest/{videoId}', [ResolverController::class, 'manifest'])
    ->whereNumber('videoId')
    ->middleware('throttle:300,1');

Route::get('/playlist', [ResolverController::class, 'playlist'])
    ->middleware('throttle:600,1');

Route::get('/seg', [ResolverController::class, 'segment'])
    ->middleware('throttle:1800,1');

Route::get('/download/{videoId}', [ResolverController::class, 'download'])
    ->whereNumber('videoId')
    ->middleware('throttle:10,1');
