<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeApiException extends RuntimeException
{
    /** Codes where Pixelforge guarantees nothing was created, so a retry is safe and useful. */
    private const RETRYABLE_CODES = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        'connection_error',
        'server_error',
    ];

    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            status: $response->status(),
            errorCode: $error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error'),
            message: $error['message'] ?? "Pixelforge responded with HTTP {$response->status()}.",
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    public static function connectionFailed(string $message): self
    {
        return new self(0, 'connection_error', $message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE_CODES, true);
    }

    /** Short status line shown to the user while we wait to retry. */
    public function waitingMessage(): string
    {
        return match ($this->errorCode) {
            'concurrency_limit_exceeded' => 'Waiting for a free slot (3 images can run at once)',
            'rate_limit_exceeded' => 'Pixelforge rate limit reached, retrying shortly',
            'overloaded' => 'Pixelforge is busy, retrying shortly',
            default => 'Could not reach Pixelforge, retrying shortly',
        };
    }

    /** Message stored on a generation that could not be submitted at all. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. Try rewording it.',
            'daily_limit_exceeded' => 'The daily image limit is used up. It resets at midnight UTC.',
            'validation_failed' => 'Pixelforge rejected the request: '.$this->getMessage(),
            'invalid_api_key' => 'The image service is misconfigured. Please contact support.',
            default => 'Pixelforge could not accept this image after several attempts. You were not charged.',
        };
    }
}
