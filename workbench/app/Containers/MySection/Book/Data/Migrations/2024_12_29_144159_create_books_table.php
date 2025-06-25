<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('books', static function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->foreignId('author_id')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Add deleted_at column for soft deletes
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
