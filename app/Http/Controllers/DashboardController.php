<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Feature;
use App\Models\Acknowledgement;
use App\Models\CallQueue;
use App\Models\User;
use App\Support\LicenceManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, LicenceManager $licences): View
    {
        $licence = $licences->current();

        return view('dashboard', [
            'licence' => $licence,
            'features' => collect(Feature::cases())->map(fn (Feature $f) => [
                'label' => $f->label(),
                'enabled' => $licence->allows($f),
            ]),
            'userCount' => User::query()->seated()->count(),
            'setupDone' => Acknowledgement::currentAccepted(),
            'queues' => $licences->allows(Feature::CallQueues) ? $request->user()->queues()->orderBy('name')->get() : collect(),
        ]);
    }

    /** An agent logs in to one of their call queues, or out of it. */
    public function queue(Request $request, CallQueue $queue): RedirectResponse
    {
        abort_unless($request->user()->queues()->whereKey($queue->id)->exists(), 404);
        $in = $request->boolean('logged_in');
        $request->user()->queues()->updateExistingPivot($queue->id, ['logged_in' => $in]);

        return back()->with('status', $in ? "You're logged in to {$queue->name}: its calls will ring you." : "You're logged out of {$queue->name}.");
    }
}
