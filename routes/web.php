<?php

/*
|--------------------------------------------------------------------------
| Root Route
|--------------------------------------------------------------------------
|
| The backend serves no web pages. The root URL only returns the API
| welcome message, so this file is loaded with the stateless "api"
| middleware group (see RouteServiceProvider).
|
*/

use App\Http\Controllers\WelcomeController;

Route::get('/', WelcomeController::class);
