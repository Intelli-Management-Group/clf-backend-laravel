<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('galleries')) {
            Schema::create('galleries', function (Blueprint $table) {
                $table->char('id', 24)->primary();
                $table->string('title')->nullable();
                $table->string('year')->nullable();
                $table->string('sub_title')->nullable();
                $table->string('catalog_thumbnail')->nullable();
                $table->timestamps(3);
            });
        }

        if (! Schema::hasTable('gallery_photos')) {
            Schema::create('gallery_photos', function (Blueprint $table) {
                $table->char('id', 24)->primary();
                $table->char('gallery_id', 24);
                $table->text('src');
                $table->unsignedInteger('position');

                $table->index(['gallery_id', 'position']);
                $table->foreign('gallery_id')->references('id')->on('galleries')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_photos');
        Schema::dropIfExists('galleries');
    }
};
