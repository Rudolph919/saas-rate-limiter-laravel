<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [ApiController::class, 'health']);

Route::get('/items', [ApiController::class, 'indexItems']);
Route::post('/items', [ApiController::class, 'storeItem']);
Route::delete('/items/{id}', [ApiController::class, 'destroyItem']);
