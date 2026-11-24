<?php

namespace App\Services\Pixelforge;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single place where provider state is applied to our database. Webhooks, the create
 * response and polling all go through here, so the rules below hold no matter how or how
 * many times a state is reported:
 *
 *  - an event id is processed at most once (unique row, inserted in the same transaction),
 *  - a succeeded/failed generation never changes again (handles out-of-order events),
 *  - the user is charged exactly once (row lock + unique ledger entry).
 */
class GenerationSettler
{
    public function __construct(private PriceCalculator $prices) {}

    /**
     * @param  array<string,mixed>  $remote  a Pixelforge generation object
     * @param  string|null  $eventId  webhook event id, for de-duplication
     */
    public function apply(array $remote, ?string $eventId = null, ?string $eventType = null): ?Generation
    {
        return DB::transaction(function () use ($remote, $eventId, $eventType) {
            if ($eventId !== null) {
                $inserted = DB::table('webhook_events')->insertOrIgnore([
                    'event_id' => $eventId,
                    'type' => $eventType ?? 'unknown',
                ]);
                if ($inserted === 0) {
                    return null; // already processed
                }
            }

            $generation = $this->find($remote);
            if ($generation === null || $generation->isFinal()) {
                return $generation;
            }

            if ($generation->provider_id === null) {
                $generation->provider_id = $remote['id'];
            }

            match ($remote['status']) {
                'processing' => $this->markProcessing($generation),
                'succeeded' => $this->markSucceeded($generation, $remote),
                'failed' => $this->markFailed($generation, $remote['error']['code'] ?? 'generation_failed', $remote['error']['message'] ?? 'The generation failed.'),
                default => null,
            };

            $generation->save();

            return $generation;
        });
    }

    /** Fail a generation that never reached Pixelforge (rejected request, gave up retrying). Costs nothing. */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::query()->lockForUpdate()->find($generation->id);
            if ($locked->isFinal()) {
                return;
            }
            $this->markFailed($locked, $code, $message);
            $locked->save();
        });
    }

    private function find(array $remote): ?Generation
    {
        $query = Generation::query()->lockForUpdate();
        $uuid = $remote['metadata']['generation_id'] ?? null;

        return ($uuid ? (clone $query)->where('uuid', $uuid)->first() : null)
            ?? $query->where('provider_id', $remote['id'])->first();
    }

    private function markProcessing(Generation $generation): void
    {
        $generation->status = 'processing';
    }

    private function markFailed(Generation $generation, string $code, string $message): void
    {
        $generation->fill([
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'provider_cost' => 0,
            'charged' => 0,
            'completed_at' => now(),
        ]);
    }

    private function markSucceeded(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'];
        $rates = $remote['rates'];
        $providerCost = $this->prices->providerCost($usage, $rates);
        $charged = $this->prices->charge($providerCost);

        $generation->fill([
            'status' => 'succeeded',
            'usage' => $usage,
            'rates' => $rates,
            'provider_cost' => $providerCost,
            'charged' => $charged,
            'image_url' => $remote['image_url'],
            'image_expires_at' => isset($remote['image_url_expires_at']) ? Carbon::parse($remote['image_url_expires_at']) : null,
            'completed_at' => isset($remote['completed_at']) ? Carbon::parse($remote['completed_at']) : now(),
        ]);

        $this->charge($generation, $charged);
    }

    private function charge(Generation $generation, int $amount): void
    {
        $user = User::query()->lockForUpdate()->find($generation->user_id);

        $created = CreditTransaction::query()->insertOrIgnore([
            'user_id' => $user->id,
            'type' => 'charge',
            'amount' => -$amount,
            'generation_id' => $generation->id,
        ]);

        if ($created) {
            $user->decrement('balance', $amount);
        }
    }
}
