<?php

namespace App\Http\Controllers;

use App\Domain\Licensing\Feature;
use App\Models\Acknowledgement;
use App\Models\User;
use App\Support\LicenceManager;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(LicenceManager $licences): View
    {
        $licence = $licences->current();

        return view('dashboard', [
            'licence' => $licence,
            'features' => collect(Feature::cases())->map(fn (Feature $f) => [
                'label' => $f->label(),
                'enabled' => $licence->allows($f),
            ]),
            'userCount' => User::count(),
            'setupDone' => Acknowledgement::currentAccepted(),
        ]);
    }
}
