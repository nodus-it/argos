<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-medialibrary was installed but never used by any model;
 * the package is gone, so is its table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('media');
    }

    public function down(): void
    {
        // Intentionally empty: the table was never used.
    }
};
