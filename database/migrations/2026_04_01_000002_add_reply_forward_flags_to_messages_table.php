<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_replied')->default(false)->after('is_starred');
            $table->boolean('is_forwarded')->default(false)->after('is_replied');
            $table->timestamp('replied_at')->nullable()->after('is_forwarded');
            $table->timestamp('forwarded_at')->nullable()->after('replied_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['is_replied', 'is_forwarded', 'replied_at', 'forwarded_at']);
        });
    }
};

