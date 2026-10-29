<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limits every query on a company's table to the current company. */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);
        if ($tenancy->isEverywhere()) {
            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenancy->current()->getKey());
    }
}
