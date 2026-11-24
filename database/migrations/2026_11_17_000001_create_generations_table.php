<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Sent to Pixelforge as the Idempotency-Key and in metadata, so webhooks can be matched
            // even if they arrive before the create call returns.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->string('status')->default('queued')->index();

            // Money in nanodollars (1 USD = 1e9).
            $table->unsignedBigInteger('estimated_cost');
            $table->unsignedBigInteger('estimated_max_cost');
            $table->unsignedBigInteger('provider_cost')->nullable();
            $table->unsignedBigInteger('charged')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('generations');
    }
};
