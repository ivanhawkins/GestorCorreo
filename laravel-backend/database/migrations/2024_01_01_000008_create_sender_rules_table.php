<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sender_email', 255);
            $table->string('target_folder', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'sender_email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_rules');
    }
};
