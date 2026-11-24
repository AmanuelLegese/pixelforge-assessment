<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0); // nanodollars
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | charge
            $table->bigInteger('amount'); // signed nanodollars
            $table->foreignId('generation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // A generation can only ever be charged once.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('balance'));
    }
};
