<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Services\Generations\GenerationStateMachine;
use App\Services\Pixelforge\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles Pixelforge webhooks (delivered at least once, in any order).
 *
 * Recording the event id and applying its effect happen in one transaction. If applying
 * fails, the event row is rolled back too and we answer 500, so Pixelforge retries and the
 * event is not lost. A duplicate delivery hits the unique index and changes nothing.
 * The work is a few indexed queries, well inside the 10 second delivery timeout.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationStateMachine $states): Response
    {
        $rawBody = $request->getContent();

        if (! $signature->isValid($rawBody, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with an invalid signature.');

            return response('Invalid signature.', 400);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response('Malformed event.', 400);
        }

        $object = $event['data']['object'] ?? null;

        DB::transaction(function () use ($event, $object, $request, $states) {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'provider_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                Log::info('Ignored duplicate Pixelforge webhook.', ['event_id' => $event['id']]);

                return;
            }

            if (! str_starts_with($event['type'], 'generation.') || ! is_array($object)) {
                return;
            }

            $generation = Generation::findForProviderObject($object);

            if (! $generation) {
                // Not created by this app (e.g. a manual API call with the same key). Acknowledge it.
                Log::warning('Pixelforge webhook for an unknown generation.', ['generation' => $object['id'] ?? null]);

                return;
            }

            $states->apply($generation, $object);
        });

        return response()->noContent();
    }
}
