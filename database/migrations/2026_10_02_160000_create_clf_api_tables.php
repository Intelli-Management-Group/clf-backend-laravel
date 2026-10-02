<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('admin');
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps(3);
        });

        Schema::create('abouts', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name');
            $table->string('title');
            $table->string('category');
            $table->longText('description');
            $table->char('parent_id', 24)->nullable()->index();
            $table->float('order')->nullable()->default(0);
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps(3);

            $table->foreign('parent_id')->references('id')->on('abouts')->nullOnDelete();
        });

        Schema::create('news', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('title');
            $table->longText('description');
            $table->dateTime('date', 3);
            $table->string('slug')->unique();
            $table->boolean('is_active')->nullable()->default(true);
            $table->timestamps(3);
        });

        Schema::create('galleries', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('title')->nullable();
            $table->string('year')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('catalog_thumbnail')->nullable();
            $table->timestamps(3);
        });

        Schema::create('gallery_photos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->char('gallery_id', 24);
            $table->text('src');
            $table->unsignedInteger('position');

            $table->foreign('gallery_id')->references('id')->on('galleries')->cascadeOnDelete();
            $table->index(['gallery_id', 'position']);
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('title')->nullable();
            $table->string('video_url')->nullable();
            $table->string('catalog_thumbnail')->nullable();
            $table->timestamps(3);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->char('id', 24)->primary();
            $table->string('name');
            $table->string('email');
            $table->string('subject');
            $table->longText('message');
            $table->timestamp('created_at', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('gallery_photos');
        Schema::dropIfExists('galleries');
        Schema::dropIfExists('news');
        Schema::dropIfExists('abouts');
        Schema::dropIfExists('admins');
    }
};
