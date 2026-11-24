<?php

return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Users pay Pixelforge's cost plus this percentage.
    'markup_percent' => 25,

    // Webhook signature timestamps older than this are rejected (replay protection).
    'webhook_tolerance_seconds' => 300,

    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS')))),
];
