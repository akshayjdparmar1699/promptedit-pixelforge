<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP wrapper around the Pixelforge API. It performs no retries itself:
 * retry policy lives in the queued job, where waiting does not block a web request.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The generation object.
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', $payload));
    }

    /** @return array<string, mixed> The generation object. */
    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($id)));
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/pricing'), authenticated: false);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request, bool $authenticated = true): array
    {
        $http = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);

        if ($authenticated) {
            $http = $http->withToken((string) $this->apiKey);
        }

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw PixelforgeApiException::connectionFailed($e->getMessage());
        }

        if (! $response->successful()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return $response->json();
    }
}
