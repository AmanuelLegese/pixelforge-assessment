<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PriceCalculator;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('generations/Create', [
            'available' => $this->money($request->user()->availableBalance()),
        ]);
    }

    public function estimate(GenerationRequest $request, PixelforgeClient $client, PriceCalculator $prices): JsonResponse
    {
        $estimate = $prices->estimate($client->pricing(), $request->prompt, $request->size, $request->quality, count($request->reference_images));

        return response()->json([
            'min' => $this->money($estimate['min']),
            'typical' => $this->money($estimate['typical']),
            'max' => $this->money($estimate['max']),
            'affordable' => $request->user()->availableBalance() >= $estimate['max'],
        ]);
    }

    public function store(GenerationRequest $request, PixelforgeClient $client, PriceCalculator $prices): RedirectResponse
    {
        $estimate = $prices->estimate($client->pricing(), $request->prompt, $request->size, $request->quality, count($request->reference_images));

        if ($request->user()->availableBalance() < $estimate['max']) {
            return back()->withErrors(['balance' => 'You do not have enough credits for this generation. The most it can cost is '.Money::display($estimate['max']).'.']);
        }

        $generation = $request->user()->generations()->create([
            'uuid' => (string) Str::uuid(),
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_images' => $request->reference_images,
            'estimated_cost' => $estimate['typical'],
            'estimated_max_cost' => $estimate['max'],
        ]);

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return to_route('generations.index');
    }

    public function index(Request $request): Response
    {
        return Inertia::render('generations/Index', [
            'generations' => $request->user()->generations()->latest('id')->limit(100)->get()->map(fn (Generation $g) => [
                'id' => $g->id,
                'prompt' => $g->prompt,
                'size' => $g->size,
                'quality' => $g->quality,
                'status' => $g->status,
                'estimated_cost' => $this->money($g->estimated_cost),
                'charged' => $g->charged === null ? null : $this->money($g->charged),
                'image_url' => $g->status === 'succeeded' && (! $g->image_expires_at || $g->image_expires_at->isFuture()) ? $g->image_url : null,
                'image_expired' => $g->status === 'succeeded' && $g->image_expires_at?->isPast(),
                'error' => $g->error_message,
                'created_at' => $g->created_at->toIso8601String(),
            ]),
            'available' => $this->money($request->user()->availableBalance()),
        ]);
    }

    /** @return array{nano:int, display:string} */
    private function money(int $nano): array
    {
        return ['nano' => $nano, 'display' => Money::display($nano)];
    }
}
