<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Call logs: for working out what went wrong. Every call, including those
 * refused before they rang anyone, with the trunk it used and why it was
 * refused or failed. The record of calls is Call history.
 */
class CallLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $problems = $request->boolean('problems');

        $calls = Call::query()->with(['user:id,name', 'trunk:id,name'])
            ->when($problems, fn ($q) => $q->whereIn('status', Call::PROBLEMS))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.call-logs', ['calls' => $calls, 'problems' => $problems]);
    }
}
