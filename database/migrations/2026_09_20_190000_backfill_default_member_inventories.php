<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        $membershipIds = DB::table('bar_memberships')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('member_inventories')
                    ->whereColumn('member_inventories.bar_membership_id', 'bar_memberships.id');
            })
            ->pluck('id');

        foreach ($membershipIds as $membershipId) {
            DB::table('member_inventories')->insertOrIgnore([
                'bar_membership_id' => $membershipId,
                'name' => 'My Shelf',
            ]);
        }
    }

    public function down(): void
    {
        // Data backfill intentionally kept on rollback to avoid deleting user inventories.
    }
};
