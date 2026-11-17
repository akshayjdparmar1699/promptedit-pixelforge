<?php

namespace App\Services\Pricing;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Current Pixelforge pricing. Cached briefly, with the last good copy kept
 * as a fallback so an outage of /v1/pricing does not block the UI.
 */
class PricingCatalog
{
    private const CACHE_KEY = 'pixelforge.pricing';

    private const FALLBACK_KEY = 'pixelforge.pricing.last_good';

    public function __construct(private readonly PixelforgeClient $client) {}

    /** @return array<string, mixed> */
    public function current(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), function () {
            try {
                $pricing = $this->client->pricing();
                Cache::forever(self::FALLBACK_KEY, $pricing);

                return $pricing;
            } catch (Throwable $e) {
                $fallback = Cache::get(self::FALLBACK_KEY);

                if ($fallback === null) {
                    throw $e;
                }

                report($e);

                return $fallback;
            }
        });
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys($this->current()['tokens']['output']['medium']);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->current()['tokens']['output']);
    }
}
