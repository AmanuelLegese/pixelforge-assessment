<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Pixelforge\GenerationSettler;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Throwable;

/** Webhook delivery isn't guaranteed, so unfinished generations are also polled. */
class PollGenerations extends Command
{
    protected $signature = 'generations:poll';

    protected $description = 'Refresh unfinished generations from Pixelforge';

    public function handle(PixelforgeClient $client, GenerationSettler $settler): void
    {
        Generation::query()
            ->whereIn('status', Generation::ACTIVE)
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds(30))
            ->each(function (Generation $generation) use ($client, $settler) {
                try {
                    $settler->apply($client->getGeneration($generation->provider_id));
                } catch (Throwable $e) {
                    report($e);
                }
            });
    }
}
