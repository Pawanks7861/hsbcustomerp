<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_name');
            $table->string('project_code')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('boqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('gst_percentage', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('boq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('item_number', 50);
            $table->text('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->decimal('rate', 15, 2)->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('boq_items')->cascadeOnDelete();
        });

        Schema::create('boq_item_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 18, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boq_item_budgets');
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('boqs');
        Schema::dropIfExists('projects');
    }
};
