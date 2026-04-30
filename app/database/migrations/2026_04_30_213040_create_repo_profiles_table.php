<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repo_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('url');
            $table->string('token');
            $table->string('default_branch')->default('main');
            $table->enum('platform', ['github', 'gitlab'])->default('github');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repo_profiles');
    }
};
