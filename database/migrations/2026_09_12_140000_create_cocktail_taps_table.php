<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cocktail_taps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cocktail_id')->constrained('cocktails')->cascadeOnDelete();
            $table->foreignId('bar_membership_id')->constrained('bar_memberships')->cascadeOnDelete();
            $table->date('tapped_on');
            $table->timestamps();

            $table->index(['bar_membership_id', 'cocktail_id', 'tapped_on'], 'cocktail_taps_member_cocktail_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cocktail_taps');
    }
};
