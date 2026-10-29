<?php

namespace App\Tenancy;

use LogicException;

/** Company data was touched before a company was chosen: a bug, never a reason to show everything. */
class NoTenant extends LogicException
{
}
