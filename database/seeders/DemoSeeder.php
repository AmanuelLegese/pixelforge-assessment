<?php

namespace Database\Seeders;

use App\Jobs\SubmitGeneration;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PriceCalculator;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo accounts (password: "password") and real Pixelforge generations that go through the normal
 * queue and webhook flow, so the admin report reconciles with Pixelforge's /v1/usage.
 */
class DemoSeeder extends Seeder
{
    public function run(PixelforgeClient $client, PriceCalculator $prices): void
    {
        $pricing = $client->pricing();

        $demo = $this->user('demo@example.test', 'Demo User', 10);
        $ana = $this->user('ana@example.test', 'Ana Customer', 5);
        $this->user('admin@example.test', 'Admin', 0);

        $requests = [
            [$demo, 'A lighthouse at dusk, watercolor [succeed]', '1536x1024', 'medium', []],
            [$demo, 'A fox in the snow [succeed]', '1024x1024', 'low', ['https://example.com/ref.png']],
            [$demo, 'This one will fail [fail]', '1024x1024', 'medium', []],
            [$ana, 'Delivered twice [duplicate] [succeed]', '1024x1536', 'high', []],
            [$ana, 'Cyberpunk market at night [succeed]', '1536x1024', 'high', ['https://example.com/a.png', 'https://example.com/b.png']],
            [$ana, 'Still working on it [slow] [succeed]', '1024x1024', 'low', []],
        ];

        foreach ($requests as [$user, $prompt, $size, $quality, $refs]) {
            $estimate = $prices->estimate($pricing, $prompt, $size, $quality, count($refs));
            $generation = $user->generations()->create([
                'uuid' => (string) Str::uuid(), 'prompt' => $prompt, 'size' => $size, 'quality' => $quality, 'reference_images' => $refs,
                'estimated_cost' => $estimate['typical'], 'estimated_max_cost' => $estimate['max'],
            ]);
            SubmitGeneration::dispatch($generation->id);
        }
    }

    private function user(string $email, string $name, int $credits): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill(['name' => $name, 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $user->balance ??= 0;
        $user->balance = max($user->balance, $credits * Money::NANO);
        $user->save();

        return $user;
    }
}
