<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 36)->unique();
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->string('gpt_label', 100)->nullable();
            $table->decimal('gpt_confidence', 5, 4)->nullable();
            $table->text('gpt_rationale')->nullable();
            $table->string('qwen_label', 100)->nullable();
            $table->decimal('qwen_confidence', 5, 4)->nullable();
            $table->text('qwen_rationale')->nullable();
            $table->string('final_label', 100);
            $table->text('final_reason')->nullable();
            $table->string('decided_by', 50);
            $table->timestamp('decided_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classifications');
    }
};
