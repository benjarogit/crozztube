<?php

use CrzSourceResolver\ResolverController;
use Illuminate\Support\Facades\Route;

/*
 * Every route uses its own throttle prefix. Laravel keys limits by IP unless a
 * prefix is supplied, so without these all endpoints would share one bucket:
 * HLS segment traffic would then exhaust the status endpoint and the watch page
 * would silently stop showing source state.
 */

Route::get('/status/slug/{slug}', [ResolverController::class, 'statusBySlug'])
    ->where('slug', '[A-Za-z0-9_-]+')
    ->middleware('throttle:240,1,crz-source-status');

Route::get('/status/{videoId}', [ResolverController::class, 'status'])
    ->whereNumber('videoId')
    ->middleware('throttle:240,1,crz-source-status');

Route::post('/refresh/{videoId}', [ResolverController::class, 'refresh'])
    ->whereNumber('videoId')
    ->middleware('throttle:20,1,crz-source-refresh');

Route::get('/manifest/{videoId}', [ResolverController::class, 'manifest'])
    ->whereNumber('videoId')
    ->middleware('throttle:300,1,crz-source-manifest');

Route::get('/playlist', [ResolverController::class, 'playlist'])
    ->middleware('throttle:600,1,crz-source-playlist');

Route::get('/seg', [ResolverController::class, 'segment'])
    ->middleware('throttle:1800,1,crz-source-seg');

Route::get('/download/{videoId}', [ResolverController::class, 'download'])
    ->whereNumber('videoId')
    ->middleware('throttle:10,1,crz-source-download');
