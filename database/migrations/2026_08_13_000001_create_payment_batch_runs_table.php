<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batch_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('window_start');
            $table->date('window_end');
            $table->timestamp('processed_at')->nullable();
            $table->string('result')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->boolean('outlook_sync_failed')->default(false);
            $table->text('outlook_sync_error')->nullable();
            $table->timestamps();
            $table->unique(['window_start', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_batch_runs');
    }
};
