<?php

use Illuminate\Support\Facades\Route;

/*
| The backend is API-only. The application UI is the React SPA in /frontend.
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name').' API',
    'version' => 'v1',
]));
