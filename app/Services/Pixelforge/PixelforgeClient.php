<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed> the generation
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->json($this->request()->withHeaders(['Idempotency-Key' => $idempotencyKey])->post('generations', $params));
    }

    /** @return array<string,mixed> */
    public function getGeneration(string $id): array
    {
        return $this->json($this->request()->get("generations/{$id}"));
    }

    /** @return array<string,mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->json($this->request()->get('usage', ['start_date' => $startDate, 'end_date' => $endDate]));
    }

    /** Published rates and token tables, cached briefly. @return array<string,mixed> */
    public function pricing(): array
    {
        return Cache::remember('pixelforge.pricing', 300, fn () => $this->json($this->http()->timeout(10)->get('pricing')));
    }

    private function request(): PendingRequest
    {
        return $this->http()->withToken(config('pixelforge.api_key'))->timeout(15);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))->acceptJson();
    }

    /** @return array<string,mixed> */
    private function json(Response $response): array
    {
        if ($response->successful()) {
            return $response->json();
        }

        $error = $response->json('error') ?? [];

        throw new PixelforgeException(
            $response->status(),
            $error['code'] ?? 'unknown_error',
            $error['message'] ?? 'Unexpected response from Pixelforge.',
            $response->header('Retry-After') !== '' ? (int) $response->header('Retry-After') : null,
        );
    }
}
