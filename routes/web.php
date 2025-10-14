<?php

use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    $database = 'ok';
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $exception) {
        $database = $exception->getMessage();
    }

    $lastRun = SyncRun::query()->latest('created_at')->first();

    return response()->json([
        'status' => 'ok',
        'database' => $database,
        'last_run' => $lastRun ? [
            'id' => $lastRun->id,
            'status' => $lastRun->status,
            'finished_at' => optional($lastRun->finished_at)->toIso8601String(),
        ] : null,
        'circuits' => [
            'sazito' => Cache::get('circuit:sazito:state', 'closed'),
            'anar360' => Cache::get('circuit:anar360:state', 'closed'),
        ],
    ]);
});
