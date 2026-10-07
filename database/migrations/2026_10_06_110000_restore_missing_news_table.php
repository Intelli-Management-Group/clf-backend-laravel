<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('news')) {
            return;
        }

        Schema::create('news', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('title');
            $table->longText('description');
            $table->dateTime('date', 3);
            $table->string('slug')->unique();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps(3);
        });
    }

    public function down(): void
    {
        // Keep the table: this repair may have been a no-op on databases where it already existed.
    }
};
