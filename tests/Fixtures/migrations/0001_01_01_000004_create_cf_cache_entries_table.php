<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_cache_entries', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key');
            $table->foreign('cache_key')->references('key')->on('cf_cache')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_cache_entries');
    }
};
