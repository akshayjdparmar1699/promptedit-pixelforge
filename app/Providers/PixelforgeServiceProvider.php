<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use App\Services\Pricing\CostCalculator;
use Illuminate\Support\ServiceProvider;

class PixelforgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
        ));

        $this->app->bind(WebhookSignature::class, fn () => new WebhookSignature(
            secret: config('pixelforge.webhook_secret'),
            toleranceSeconds: (int) config('pixelforge.signature_tolerance'),
        ));

        $this->app->bind(CostCalculator::class, fn () => new CostCalculator(
            markupPercent: (int) config('pixelforge.markup_percent'),
        ));
    }
}
