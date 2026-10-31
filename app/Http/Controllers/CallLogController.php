<?php

namespace App\Http\Controllers;

use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Call history: the record of calls. Why calls were refused is in Admin → Call logs. */
class CallLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        // Calls refused before they reached anyone aren't part of the record.
        $query = Call::query()->with('user:id,name')->where('status', '!=', Call::STATUS_REFUSED)->latest();

        // Users see their own calls; admins see everyone's.
        if (! $request->user()->isAdmin()) {
            $query->where('user_id', $request->user()->id);
        }

        return view('calls', ['calls' => $query->paginate(25)]);
    }
}
