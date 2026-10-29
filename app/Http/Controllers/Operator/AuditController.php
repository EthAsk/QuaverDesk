<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\OperatorAudit;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function __invoke(): View
    {
        return view('operator.audit', ['entries' => OperatorAudit::query()->with('operator')->latest('id')->paginate(50)]);
    }
}
