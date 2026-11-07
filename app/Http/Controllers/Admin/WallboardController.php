<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Wallboard;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/** Live wallboard (Pro): admins watch calls, queues and who's free, refreshed every few seconds. */
class WallboardController extends Controller
{
    public const REFRESH_SECONDS = 5;

    public function show(Wallboard $wallboard): View
    {
        return view('admin.wallboard', ['initial' => $wallboard->snapshot(), 'refresh' => self::REFRESH_SECONDS]);
    }

    public function data(Wallboard $wallboard): JsonResponse
    {
        return response()->json($wallboard->snapshot(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
