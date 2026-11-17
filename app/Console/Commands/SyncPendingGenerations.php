<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Polling fallback. Webhook delivery is not guaranteed, so generations that have been
 * active for a while are fetched directly. Kept small per run to stay well inside the
 * 60 requests per minute shared with submissions.
 */
class SyncPendingGenerations extends Command
{
    protected $signature = 'pixelforge:sync {--limit=10}';

    protected $description = 'Poll Pixelforge for generations that have not reached a final state';

    public function handle(PixelforgeClient $client, GenerationStateMachine $states): int
    {
        $generations = Generation::active()
            ->whereNotNull('provider_id')
            ->where('created_at', '<', now()->subMinute())
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<', now()->subMinute()))
            ->orderBy('last_polled_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            $generation->update(['last_polled_at' => now()]);

            try {
                $states->apply($generation, $client->getGeneration($generation->provider_id));
                $this->line("Synced {$generation->provider_id}");
            } catch (PixelforgeApiException $e) {
                $this->warn("Could not sync {$generation->provider_id}: {$e->errorCode}");

                if ($e->errorCode === 'rate_limit_exceeded') {
                    break;
                }
            }
        }

        return self::SUCCESS;
    }
}
