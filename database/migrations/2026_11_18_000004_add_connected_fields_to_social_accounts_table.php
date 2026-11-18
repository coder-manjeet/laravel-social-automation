<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('name')->nullable()->after('account_id');
            $table->foreignId('connected_by')->nullable()->after('meta')->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable()->after('connected_by');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('connected_by');
            $table->dropColumn(['name', 'connected_at']);
        });
    }
};
