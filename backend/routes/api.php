<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ReviewController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies', [CompanyController::class, 'store']);
    Route::get('/companies/{company}', [CompanyController::class, 'show']);
    Route::post('/companies/{company}/parse', [CompanyController::class, 'parse']);
    Route::get('/companies/{company}/reviews', [ReviewController::class, 'index']);
});
