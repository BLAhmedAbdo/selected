<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

Route::get('/normal', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'Normal response',
    ]);
});

Route::get('/slow', function (Request $request) {
    if ($request->query('hard') == 1) {
        sleep(rand(5, 7));
    } else {
        sleep(2);
    }

    return response()->json([
        'status' => 'ok',
        'message' => 'Slow response',
        'hard_mode' => $request->query('hard') == 1,
    ]);
});

Route::get('/error', function () {
    throw new \Exception('Simulated system error');
});

Route::get('/random', function () {
    $rand = rand(1, 3);

    if ($rand === 1) {
        return response()->json([
            'status' => 'ok',
            'type' => 'normal',
        ]);
    }

    if ($rand === 2) {
        sleep(2);

        return response()->json([
            'status' => 'ok',
            'type' => 'slow',
        ]);
    }

    throw new \Exception('Random error occurred');
});

Route::get('/db', function (Request $request) {
    if ($request->query('fail') == 1) {
        DB::select('SELECT * FROM table_that_does_not_exist');
    }

    $users = DB::select('SELECT * FROM users LIMIT 1');

    return response()->json([
        'status' => 'ok',
        'message' => 'Database query success',
        'data_count' => count($users),
    ]);
});

Route::post('/validate', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'email' => ['required', 'email'],
        'age' => ['required', 'integer', 'between:18,60'],
    ]);

    $validator->validate();

    return response()->json([
        'status' => 'ok',
        'message' => 'Validation passed',
        'data' => $request->only(['email', 'age']),
    ]);
});