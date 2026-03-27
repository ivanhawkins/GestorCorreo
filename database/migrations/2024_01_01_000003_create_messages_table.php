<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            // UUID string primary key
            $table->string('id', 36)->primary();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->integer('imap_uid')->nullable();
            $table->string('message_id', 500)->nullable();
            $table->string('thread_id', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('from_email', 255);
            $table->text('to_addresses')->nullable();
            $table->text('cc_addresses')->nullable();
            $table->text('bcc_addresses')->nullable();
            $table->string('subject', 500)->nullable();
            $table->timestamp('date')->nullable();
            $table->text('snippet')->nullable();
            $table->string('folder', 255)->default('INBOX');
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('account_id');
            $table->index('folder');
            $table->index('date');
            $table->index('is_read');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
