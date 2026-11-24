<?php

namespace Tests\Feature;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PRICING = [
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4, 'text_token_variance' => 0.15, 'per_reference_image' => 765,
            'output' => ['low' => ['1024x1024' => 280], 'medium' => ['1024x1024' => 1100], 'high' => ['1024x1024' => 4300]],
            'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
        ],
    ];

    private array $payload = ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => []];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.api_key' => 'pf_test']);
    }

    public function test_it_blocks_generating_when_the_worst_case_cost_is_not_affordable(): void
    {
        Http::fake(['*/pricing' => Http::response(self::PRICING)]);
        Queue::fake();
        $user = User::factory()->create(['balance' => 1_000]);

        $this->actingAs($user)->post('/generations', $this->payload)->assertSessionHasErrors('balance');

        Queue::assertNothingPushed();
        $this->assertSame(0, Generation::count());
    }

    public function test_it_records_an_estimate_and_queues_the_submission(): void
    {
        Http::fake(['*/pricing' => Http::response(self::PRICING)]);
        Queue::fake();
        $user = User::factory()->create(['balance' => 1_000_000_000]);

        $this->actingAs($user)->post('/generations', $this->payload)->assertRedirect('/generations');

        $generation = Generation::first();
        $this->assertSame('queued', $generation->status);
        $this->assertGreaterThan(0, $generation->estimated_cost);
        $this->assertGreaterThanOrEqual($generation->estimated_cost, $generation->estimated_max_cost);
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_in_flight_generations_reserve_their_worst_case_cost(): void
    {
        Http::fake(['*/pricing' => Http::response(self::PRICING)]);
        Queue::fake();
        $user = User::factory()->create(['balance' => 100_000_000]);
        $user->generations()->create([
            'uuid' => 'u1', 'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_cost' => 1, 'estimated_max_cost' => 99_000_000,
        ]);

        $this->assertSame(1_000_000, $user->availableBalance());
    }

    public function test_the_submit_job_sends_the_uuid_as_idempotency_key_and_fails_rejections_for_free(): void
    {
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'content_policy_violation', 'message' => 'Prompt rejected.']], 400)]);
        $user = User::factory()->create();
        $generation = $user->generations()->create([
            'uuid' => 'abc-123', 'prompt' => 'x [blocked]', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_cost' => 1, 'estimated_max_cost' => 1,
        ]);

        SubmitGeneration::dispatchSync($generation->id);

        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === ['abc-123'] && $request['metadata'] === ['generation_id' => 'abc-123']);
        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertSame(0, $generation->charged);
        $this->assertSame('content_policy_violation', $generation->error_code);
    }

    public function test_the_submit_job_keeps_the_generation_queued_when_rate_limited(): void
    {
        Http::fake(['*/generations' => Http::response(['error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'Too many.']], 429, ['Retry-After' => '5'])]);
        $user = User::factory()->create();
        $generation = $user->generations()->create([
            'uuid' => 'abc-456', 'prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [],
            'estimated_cost' => 1, 'estimated_max_cost' => 1,
        ]);

        $job = new SubmitGeneration($generation->id);
        $job->withFakeQueueInteractions()->handle(app(\App\Services\Pixelforge\PixelforgeClient::class), app(\App\Services\Pixelforge\GenerationSettler::class));

        $job->assertReleased();
        $this->assertSame('queued', $generation->fresh()->status);
    }
}
