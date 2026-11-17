<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const TYPE_PURCHASE = 'purchase';

    /** Holds the maximum possible charge while a generation runs. */
    public const TYPE_RESERVE = 'reserve';

    /** Refunds the unused part of the reserve once the actual cost is known. */
    public const TYPE_SETTLE = 'settle';

    /** Refunds the whole reserve for a failed generation. */
    public const TYPE_RELEASE = 'release';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_nanos' => 'integer',
            'balance_after_nanos' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
