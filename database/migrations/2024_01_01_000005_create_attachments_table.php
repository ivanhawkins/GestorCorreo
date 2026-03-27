<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 36);
            $table->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $table->string('filename', 500);
            $table->string('mime_type', 255)->nullable();
            $table->integer('size_bytes')->nullable();
            $table->string('local_path', 1000);

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
