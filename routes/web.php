<?php

use App\Models\SyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

Route::get('/run/sazito-sync', function (\Illuminate\Http\Request $request) {

    // 🧠 Parse options
    $page = $request->get('page', 1);
    $limit = $request->get('limit');
    $all = $request->boolean('all', false);

    // 🚀 Execute the command
    Artisan::call('sync:sazito-products', [
        '--page' => $page,
        '--limit' => $limit,
        '--all' => $all ? 'true' : 'false',
    ]);

    return response()->json([
        'status' => 'ok',
        'message' => 'Sazito sync command executed successfully.',
        'output' => trim(Artisan::output()),
    ]);
});

Route::get('/run/migrate', function (\Illuminate\Http\Request $request) {
    // 🚀 Run migrations
    Artisan::call('migrate', ['--force' => true]);

    return response()->json([
        'status' => 'ok',
        'message' => 'Migrations executed successfully.',
        'output' => trim(Artisan::output()),
    ]);
});

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
