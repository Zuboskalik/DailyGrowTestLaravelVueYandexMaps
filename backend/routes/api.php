<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Temporary stub, replaced by CompanyController@index in Phase 4.
Route::middleware('auth:sanctum')->get('/companies', function () {
    return response()->json(['data' => []]);
});
