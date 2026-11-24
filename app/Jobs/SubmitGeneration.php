<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationSettler;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends a generation to Pixelforge. Safe to run any number of times: the generation's uuid is
 * the Idempotency-Key, so a retry after a timeout returns the existing generation instead of
 * creating (and billing) a second one.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    public $tries = 0; // bounded by retryUntil() instead, because rate-limit retries are expected

    public function __construct(public int $generationId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(PixelforgeClient $client, GenerationSettler $settler): void
    {
        $generation = Generation::find($this->generationId);
        if ($generation === null || $generation->provider_id !== null || $generation->isFinal()) {
            return;
        }

        try {
            $remote = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->uuid],
            ], $generation->uuid);
        } catch (PixelforgeException $e) {
            if ($e->errorCode === 'daily_limit_exceeded') {
                $settler->failLocally($generation, $e->errorCode, 'The daily generation limit has been reached. Try again tomorrow.');
            } elseif ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 5, 1) + random_int(0, 3)); // nothing was created: try again
            } else {
                $settler->failLocally($generation, $e->errorCode, $e->getMessage());
            }

            return;
        } catch (ConnectionException) {
            $this->release(10); // unknown outcome; the idempotency key makes the retry safe

            return;
        }

        $settler->apply($remote);
    }

    public function failed(Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation && $generation->provider_id === null) {
            app(GenerationSettler::class)->failLocally($generation, 'submission_failed', 'We could not reach the image provider. You were not charged.');
        }
    }
}
