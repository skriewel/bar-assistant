<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cocktails', function (Blueprint $table) {
            $table->string('publication')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('cocktails', function (Blueprint $table) {
            $table->dropColumn('publication');
        });
    }
};
