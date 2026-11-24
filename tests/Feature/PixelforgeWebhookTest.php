<?php

namespace Tests\Feature;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $generation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pixelforge.webhook_secret' => 'whsec_test']);
        $this->user = User::factory()->create(['balance' => 10_000_000_000]);
        $this->generation = $this->user->generations()->create([
            'uuid' => '11111111-1111-1111-1111-111111111111', 'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low',
            'reference_images' => [], 'estimated_cost' => 1, 'estimated_max_cost' => 1,
        ]);
    }

    private function remote(string $status, array $extra = []): array
    {
        return array_merge([
            'id' => 'gen_abc', 'object' => 'generation', 'status' => $status,
            'metadata' => ['generation_id' => '11111111-1111-1111-1111-111111111111'],
        ], $extra);
    }

    private function succeeded(array $override = []): array
    {
        return array_merge($this->remote('succeeded', [
            'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 765, 'output_tokens' => 280, 'reasoning_tokens' => 100, 'total_tokens' => 1155],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'image_url' => 'https://img.test/a.png', 'image_url_expires_at' => now()->addDay()->toIso8601String(), 'completed_at' => now()->toIso8601String(),
        ]), $override);
    }

    private function deliver(string $id, string $type, array $object, string $secret = 'whsec_test', ?int $timestamp = null)
    {
        $body = json_encode(['id' => $id, 'type' => $type, 'created_at' => now()->toIso8601String(), 'data' => ['object' => $object]]);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], [
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_it_rejects_bad_signatures_and_stale_timestamps(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->succeeded(), 'wrong-secret')->assertStatus(400);
        $this->deliver('evt_2', 'generation.succeeded', $this->succeeded(), 'whsec_test', time() - 600)->assertStatus(400);

        $this->assertSame('queued', $this->generation->fresh()->status);
    }

    public function test_it_charges_exact_cost_plus_markup_once_when_an_event_is_delivered_twice(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->succeeded())->assertNoContent();
        $this->deliver('evt_1', 'generation.succeeded', $this->succeeded())->assertNoContent();

        // (10*5 + 765*10 + 280*40 + 100*12.5) / 1e6 = 0.020150 USD; *1.25 = 0.0251875
        $generation = $this->generation->fresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertSame(20_150_000, $generation->provider_cost);
        $this->assertSame(25_187_500, $generation->charged);
        $this->assertSame(1, CreditTransaction::count());
        $this->assertSame(10_000_000_000 - 25_187_500, $this->user->fresh()->balance);
    }

    public function test_it_does_not_charge_twice_when_different_events_report_the_same_success(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->succeeded())->assertNoContent();
        $this->deliver('evt_2', 'generation.succeeded', $this->succeeded())->assertNoContent();

        $this->assertSame(1, CreditTransaction::count());
        $this->assertSame(10_000_000_000 - 25_187_500, $this->user->fresh()->balance);
    }

    public function test_a_late_processing_event_does_not_undo_success(): void
    {
        $this->deliver('evt_2', 'generation.succeeded', $this->succeeded())->assertNoContent();
        $this->deliver('evt_1', 'generation.processing', $this->remote('processing'))->assertNoContent();

        $this->assertSame('succeeded', $this->generation->fresh()->status);
    }

    public function test_final_states_are_never_overwritten(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->succeeded())->assertNoContent();
        $this->deliver('evt_2', 'generation.failed', $this->remote('failed', ['error' => ['code' => 'internal_error', 'message' => 'x']]))->assertNoContent();
        $this->assertSame('succeeded', $this->generation->fresh()->status);

        $other = $this->user->generations()->create([
            'uuid' => '22222222-2222-2222-2222-222222222222', 'prompt' => 'b', 'size' => '1024x1024', 'quality' => 'low',
            'reference_images' => [], 'estimated_cost' => 1, 'estimated_max_cost' => 1,
        ]);
        $override = ['id' => 'gen_def', 'metadata' => ['generation_id' => $other->uuid]];
        $this->deliver('evt_3', 'generation.failed', $this->remote('failed', $override + ['error' => ['code' => 'generation_failed', 'message' => 'Model failed']]))->assertNoContent();
        $this->deliver('evt_4', 'generation.succeeded', $this->succeeded($override))->assertNoContent();

        $this->assertSame('failed', $other->fresh()->status);
        $this->assertSame(0, $other->fresh()->charged);
        $this->assertSame(1, CreditTransaction::count());
    }

    public function test_it_moves_queued_to_processing(): void
    {
        $this->deliver('evt_1', 'generation.processing', $this->remote('processing'))->assertNoContent();

        $this->assertSame('processing', $this->generation->fresh()->status);
    }

    public function test_it_answers_pings(): void
    {
        $this->deliver('evt_ping', 'ping', ['id' => 'x'])->assertNoContent();
    }
}
