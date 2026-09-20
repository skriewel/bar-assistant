<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Carbon\Carbon;
use Tests\TestCase;
use Kami\Cocktail\Models\Ingredient;
use Illuminate\Support\Facades\DB;
use Kami\Cocktail\Models\Enums\AbilityEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MemberInventoryTokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_read_token_can_resolve_profile_and_read_own_inventory(): void
    {
        $membership = $this->setupBarMembership();
        $user = $membership->user;
        $inventoryId = DB::table('member_inventories')->insertGetId([
            'bar_membership_id' => $membership->id,
            'name' => 'My Shelf',
        ]);

        $ingredient = Ingredient::factory()->create([
            'bar_id' => $membership->bar_id,
            'created_user_id' => $user->id,
        ]);

        DB::table('member_inventory_ingredients')->insert([
            'member_inventory_id' => $inventoryId,
            'ingredient_id' => $ingredient->id,
        ]);

        $token = $user->createToken(
            'inventory-reader',
            [AbilityEnum::InventoryRead->value],
            Carbon::now()->addMonth(),
        );

        $auth = ['Authorization' => 'Bearer '.$token->plainTextToken];
        $barHeaders = [
            ...$auth,
            'Bar-Assistant-Bar-Id' => (string) $membership->bar_id,
        ];

        $this->getJson('/api/profile', $auth)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->getJson('/api/members/'.$user->id.'/inventories', $barHeaders)
            ->assertOk()
            ->assertJsonPath('data.0.id', $inventoryId)
            ->assertJsonPath('data.0.name', 'My Shelf');

        $this->getJson(
            '/api/members/'.$user->id.'/inventories/'.$inventoryId.'/ingredients',
            $barHeaders,
        )
            ->assertOk()
            ->assertJsonPath('data.0.id', $ingredient->id);

        $this->postJson(
            '/api/members/'.$user->id.'/inventories/'.$inventoryId.'/ingredients/batch-store',
            ['ingredients' => [$ingredient->id]],
            $barHeaders,
        )->assertForbidden();
    }

    public function test_inventory_write_token_can_add_ingredients_but_cannot_read_inventory(): void
    {
        $membership = $this->setupBarMembership();
        $user = $membership->user;
        $inventoryId = DB::table('member_inventories')->insertGetId([
            'bar_membership_id' => $membership->id,
            'name' => 'My Shelf',
        ]);

        $ingredient = Ingredient::factory()->create([
            'bar_id' => $membership->bar_id,
            'created_user_id' => $user->id,
        ]);

        $token = $user->createToken(
            'inventory-writer',
            [AbilityEnum::InventoryWrite->value],
            Carbon::now()->addMonth(),
        );

        $headers = [
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Bar-Assistant-Bar-Id' => (string) $membership->bar_id,
        ];

        $this->getJson('/api/members/'.$user->id.'/inventories', $headers)
            ->assertForbidden();

        $this->postJson(
            '/api/members/'.$user->id.'/inventories/'.$inventoryId.'/ingredients/batch-store',
            ['ingredients' => [$ingredient->id]],
            $headers,
        )->assertNoContent();

        $this->assertDatabaseHas('member_inventory_ingredients', [
            'member_inventory_id' => $inventoryId,
            'ingredient_id' => $ingredient->id,
        ]);
    }

    public function test_inventory_token_cannot_access_another_users_inventory(): void
    {
        $membership = $this->setupBarMembership();
        $owner = $membership->user;

        $inventoryId = DB::table('member_inventories')->insertGetId([
            'bar_membership_id' => $membership->id,
            'name' => 'Owner Shelf',
        ]);

        $otherMembership = $this->setupBarMembership();
        $other = $otherMembership->user;
        $token = $other->createToken(
            'inventory-client',
            [
                AbilityEnum::InventoryRead->value,
                AbilityEnum::InventoryWrite->value,
            ],
            Carbon::now()->addMonth(),
        );

        $headers = [
            'Authorization' => 'Bearer '.$token->plainTextToken,
            'Bar-Assistant-Bar-Id' => (string) $membership->bar_id,
        ];

        $this->getJson('/api/members/'.$owner->id.'/inventories', $headers)
            ->assertForbidden();

        $this->postJson(
            '/api/members/'.$owner->id.'/inventories/'.$inventoryId.'/ingredients/batch-store',
            ['ingredients' => []],
            $headers,
        )->assertForbidden();
    }
}
