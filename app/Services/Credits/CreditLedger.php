<?php

namespace App\Services\Credits;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Append-only credit ledger. users.balance_nanos is always equal to the sum of the
 * user's entries because both are written in one transaction under a row lock.
 *
 * Lifecycle of a generation:
 *   reserve  -max            when it is created (blocks generating without enough credits)
 *   settle   +(max - actual) when it succeeds
 *   release  +max            when it fails, so failures are free
 */
class CreditLedger
{
    public function purchase(User $user, int $dollars): CreditTransaction
    {
        return $this->record($user, null, CreditTransaction::TYPE_PURCHASE, Money::dollarsToNanos($dollars), "Bought \${$dollars} of credits");
    }

    /** @throws InsufficientCreditsException */
    public function reserve(User $user, Generation $generation, int $nanos): CreditTransaction
    {
        return DB::transaction(function () use ($user, $generation, $nanos) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->balance_nanos < $nanos) {
                throw new InsufficientCreditsException($nanos, $locked->balance_nanos);
            }

            return $this->record($locked, $generation, CreditTransaction::TYPE_RESERVE, -$nanos, 'Reserved for image #'.$generation->id);
        });
    }

    /**
     * Refunds the difference between the reserve and the actual charge.
     * The result can be negative only if Pixelforge rates rose between estimate and creation.
     */
    public function settle(Generation $generation, int $chargeNanos): ?CreditTransaction
    {
        return $this->recordOnce(
            $generation,
            CreditTransaction::TYPE_SETTLE,
            $generation->reserved_nanos - $chargeNanos,
            'Image #'.$generation->id.' finished, unused reserve refunded',
        );
    }

    public function release(Generation $generation): ?CreditTransaction
    {
        return $this->recordOnce(
            $generation,
            CreditTransaction::TYPE_RELEASE,
            $generation->reserved_nanos,
            'Image #'.$generation->id.' failed, reserve refunded',
        );
    }

    private function recordOnce(Generation $generation, string $type, int $amountNanos, string $description): ?CreditTransaction
    {
        return DB::transaction(function () use ($generation, $type, $amountNanos, $description) {
            $alreadyFinal = CreditTransaction::where('generation_id', $generation->id)
                ->whereIn('type', [CreditTransaction::TYPE_SETTLE, CreditTransaction::TYPE_RELEASE])
                ->exists();

            if ($alreadyFinal) {
                return null;
            }

            $user = User::whereKey($generation->user_id)->lockForUpdate()->firstOrFail();

            return $this->record($user, $generation, $type, $amountNanos, $description);
        });
    }

    private function record(User $user, ?Generation $generation, string $type, int $amountNanos, string $description): CreditTransaction
    {
        return DB::transaction(function () use ($user, $generation, $type, $amountNanos, $description) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $locked->balance_nanos += $amountNanos;
            $locked->save();

            return CreditTransaction::create([
                'user_id' => $locked->id,
                'generation_id' => $generation?->id,
                'type' => $type,
                'amount_nanos' => $amountNanos,
                'balance_after_nanos' => $locked->balance_nanos,
                'description' => $description,
            ]);
        });
    }
}
