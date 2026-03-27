<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_config', function (Blueprint $table) {
            $table->id();
            $table->string('api_url', 500);
            $table->text('api_key');
            $table->string('primary_model', 100);
            $table->string('secondary_model', 100);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_config');
    }
};
