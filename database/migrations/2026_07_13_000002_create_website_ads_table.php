<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_ads', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('placement', 40)->index();
            $table->string('creative_type', 20)->default('image');
            $table->string('title', 180)->nullable();
            $table->text('body')->nullable();
            $table->longText('html_content')->nullable();
            $table->text('image_url')->nullable();
            $table->text('target_url')->nullable();
            $table->string('display_style', 20)->default('card');
            $table->string('device', 20)->default('all');
            $table->string('audience', 20)->default('all');
            $table->string('label', 40)->default('Sponsored');
            $table->string('background_color', 20)->nullable();
            $table->unsignedSmallInteger('height')->default(90);
            $table->unsignedSmallInteger('max_width')->nullable();
            $table->unsignedTinyInteger('every_n_items')->default(6);
            $table->integer('priority')->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->boolean('dismissible')->default(false);
            $table->boolean('open_new_tab')->default(true);
            $table->boolean('show_label')->default(true);
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('ends_at')->nullable()->index();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_ads');
    }
};
