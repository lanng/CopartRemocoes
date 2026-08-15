<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->date('window_start');
            $table->date('window_end');
            $table->timestamp('generated_at');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->boolean('outlook_sync_failed')->default(false);
            $table->text('outlook_sync_error')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['window_start', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_batches');
    }
};
