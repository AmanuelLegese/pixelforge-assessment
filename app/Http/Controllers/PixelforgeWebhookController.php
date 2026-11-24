<?php

namespace App\Http\Controllers;

use App\Services\Pixelforge\GenerationSettler;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, GenerationSettler $settler): Response
    {
        $raw = $request->getContent(); // verify against the raw body, before any decoding

        abort_unless($this->validSignature($raw, (string) $request->header('Pixelforge-Signature')), 400, 'Invalid signature.');

        $event = json_decode($raw, true);
        abort_unless(is_array($event) && isset($event['id'], $event['type']), 400, 'Malformed event.');

        if ($event['type'] !== 'ping') {
            $settler->apply($event['data']['object'], $event['id'], $event['type']);
        }

        return response()->noContent();
    }

    private function validSignature(string $raw, string $header): bool
    {
        $secret = config('pixelforge.webhook_secret');
        if (! $secret) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > config('pixelforge.webhook_tolerance_seconds')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
