<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_charge_nanos' => 'integer',
            'reserved_nanos' => 'integer',
            'provider_cost_nanos' => 'integer',
            'charge_nanos' => 'integer',
            'image_url_expires_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_polled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::activeValues());
    }

    /**
     * Find the local record for a provider generation object. Falls back to our own id in
     * metadata, because a webhook can arrive before POST /v1/generations has returned.
     *
     * @param  array<string, mixed>  $object
     */
    public static function findForProviderObject(array $object): ?self
    {
        if (! empty($object['id'])) {
            $byProviderId = self::where('provider_id', $object['id'])->first();

            if ($byProviderId) {
                return $byProviderId;
            }
        }

        $localId = $object['metadata']['app_generation_id'] ?? null;

        if (! is_string($localId) || ! ctype_digit($localId)) {
            return null;
        }

        $generation = self::find((int) $localId);

        // Never attach a provider generation to a record already linked to a different one.
        if ($generation && $generation->provider_id && $generation->provider_id !== ($object['id'] ?? null)) {
            return null;
        }

        return $generation;
    }

    /** @return array<string, mixed> Request body for POST /v1/generations. */
    public function toProviderPayload(): array
    {
        $payload = [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'metadata' => ['app_generation_id' => (string) $this->id],
        ];

        if (! empty($this->reference_images)) {
            $payload['reference_images'] = array_values($this->reference_images);
        }

        return $payload;
    }
}
