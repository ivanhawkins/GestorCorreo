<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('email_address', 255);
            $table->string('imap_host', 255);
            $table->string('smtp_host', 255);
            $table->integer('imap_port');
            $table->integer('smtp_port');
            $table->string('username', 255);
            $table->text('encrypted_password');
            $table->boolean('is_active')->default(true);
            $table->boolean('ssl_verify')->default(true);
            $table->integer('connection_timeout')->default(30);
            $table->boolean('auto_classify')->default(false);
            $table->integer('auto_sync_interval')->default(0);
            $table->text('custom_classification_prompt')->nullable();
            $table->text('custom_review_prompt')->nullable();
            $table->text('owner_profile')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->string('protocol', 10)->default('imap');
            $table->bigInteger('mailbox_storage_bytes')->nullable();
            $table->bigInteger('mailbox_storage_limit')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'email_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
