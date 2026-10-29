<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CallLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $query = Call::query()->with('user:id,name')->latest();

        // Users see their own calls; admins see everyone's.
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return view('calls', ['calls' => $query->paginate(25)]);
    }
}
