<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Everything an operator does, from the console or the command line. Never edited or deleted by the app. */
class OperatorAudit extends Model
{
    protected $table = 'operator_audit';

    public const UPDATED_AT = null;

    protected $fillable = ['operator_id', 'action', 'tenant_id', 'tenant_name', 'details', 'ip_address'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /** @param array<string, mixed> $details */
    public static function record(string $action, ?Tenant $tenant = null, array $details = []): self
    {
        $operator = auth('operator')->user();

        return static::query()->create([
            'operator_id' => $operator?->getKey(),
            'action' => $action,
            'tenant_id' => $tenant?->getKey(),
            'tenant_name' => $tenant?->name,
            'details' => $details ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
