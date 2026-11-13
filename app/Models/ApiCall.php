<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One request to place a call over the API: who asked, for whom, to where, and what came of it. */
class ApiCall extends Model
{
    use BelongsToTenant;

    protected $attributes = ['status' => 'requested'];

    protected $fillable = ['api_token_id', 'token_name', 'user_id', 'to', 'prefix', 'idempotency_key', 'call_id', 'status', 'reason', 'ip'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The person's phone rang and was answered; or nobody has answered yet. */
    public function outcome(): string
    {
        if ($this->status === 'requested' && $this->created_at->lt(now()->subSeconds(45))) {
            return 'no_answer';
        }

        return $this->status;
    }
}
