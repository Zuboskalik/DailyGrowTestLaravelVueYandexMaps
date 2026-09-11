<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('normalized_url', 2048)->nullable()->index();
            $table->string('yandex_id', 64)->nullable()->unique();
            $table->string('name')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('reviews_count')->default(0);
            $table->unsignedInteger('ratings_count')->nullable();
            $table->enum('parse_status', ['idle', 'pending', 'processing', 'completed', 'failed'])
                ->default('idle');
            $table->timestamp('last_parsed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
