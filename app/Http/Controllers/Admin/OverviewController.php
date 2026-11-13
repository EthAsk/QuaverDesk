<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Licensing\Feature;
use App\Http\Controllers\Controller;
use App\Models\Acknowledgement;
use App\Models\User;
use App\Support\LicenceManager;
use App\Support\Modules;
use Illuminate\View\View;

/** The admin overview: plan, seats, licence expiry and which features are on. */
class OverviewController extends Controller
{
    public function __invoke(LicenceManager $licences): View
    {
        $licence = $licences->current();

        return view('admin.overview', [
            'licence' => $licence,
            'features' => collect(Feature::cases())->map(fn (Feature $f) => [
                'label' => $f->label(),
                'enabled' => $licence->allows($f),
            ])->concat(collect(app(Modules::class)->features())->map(fn (array $m) => [
                'label' => $m['label'],
                'enabled' => $licence->plan->atLeast($m['plan']),
            ])->values()),
            'userCount' => User::query()->seated()->count(),
            'setupDone' => Acknowledgement::currentAccepted(),
        ]);
    }
}
