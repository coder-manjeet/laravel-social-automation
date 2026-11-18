<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            $table->foreignId('post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->json('captions')->nullable();
            $table->json('platforms')->nullable();
            $table->string('link_url')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
