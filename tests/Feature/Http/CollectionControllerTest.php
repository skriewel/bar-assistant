<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Collection;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\BarMembership;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CollectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private BarMembership $barMembership;

    public function setUp(): void
    {
        parent::setUp();

        $this->barMembership = $this->setupBarMembership();
        $this->actingAs($this->barMembership->user);
    }

    public function test_list_user_collections_response(): void
    {
        Collection::factory()->recycle($this->barMembership->bar)->for($this->barMembership)->count(10)->create();

        $this->withHeader('Bar-Assistant-Bar-Id', (string) $this->barMembership->bar_id);
        $response = $this->getJson('/api/collections');

        $response->assertOk();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data', 10)
                ->etc()
        );
    }

    public function test_show_user_collection_response(): void
    {
        $collection = Collection::factory()->for($this->barMembership)->create([
            'name' => 'TEST',
            'description' => 'Description',
        ]);

        $response = $this->getJson('/api/collections/' . $collection->id);

        $response->assertOk();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data')
                ->where('data.id', $collection->id)
                ->where('data.name', 'TEST')
                ->where('data.description', 'Description')
                ->where('data.cocktails', [])
                ->etc()
        );
    }

    public function test_create_collection_response(): void
    {
        $cocktail = Cocktail::factory()->for($this->barMembership->bar)->create();

        $this->withHeader('Bar-Assistant-Bar-Id', (string) $this->barMembership->bar_id);
        $response = $this->postJson('/api/collections', [
            'name' => 'TEST',
            'description' => 'Description',
            'cocktails' => [$cocktail->id]
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->headers->get('Location'));
    }

    public function test_create_collection_does_not_add_cocktail_from_another_bar_response(): void
    {
        $cocktail1 = Cocktail::factory()->for($this->barMembership->bar)->create();
        $cocktail2 = Cocktail::factory()->create();

        $this->withHeader('Bar-Assistant-Bar-Id', (string) $this->barMembership->bar_id);
        $response = $this->postJson('/api/collections', [
            'name' => 'TEST',
            'description' => 'Description',
            'cocktails' => [$cocktail1->id, $cocktail2->id]
        ]);

        $response->assertUnprocessable();
    }

    public function test_update_collections_response(): void
    {
        $model = Collection::factory()->for($this->barMembership)->create([
            'name' => 'TEST',
            'description' => 'Description',
        ]);

        $response = $this->putJson('/api/collections/' . $model->id, [
            'name' => 'TEST 2',
            'description' => 'Description 2',
        ]);

        $response->assertNoContent();
    }

    public function test_delete_collection_response(): void
    {
        $model = Collection::factory()->for($this->barMembership)->create([
            'name' => 'TEST',
            'description' => 'Description',
        ]);

        $response = $this->delete('/api/collections/' . $model->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('collections', ['id' => $model->id]);
    }

    public function test_list_shared_collections_in_a_bar(): void
    {
        Collection::factory()->for($this->barMembership)->count(5)->create();
        Collection::factory()->for($this->barMembership)->count(3)->create([
            'is_bar_shared' => true
        ]);

        $response = $this->getJson('/api/bars/' . $this->barMembership->bar_id . '/collections');

        $response->assertOk();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data', 3)
                ->etc()
        );
    }

    public function test_sync_cocktails_in_collection(): void
    {
        $cocktailsInCollection = Cocktail::factory()->for($this->barMembership->bar)->count(3);

        $model = Collection::factory()
            ->for($this->barMembership)
            ->has($cocktailsInCollection)
            ->create([
                'name' => 'TEST',
                'description' => 'Description',
            ]);

        $newCocktailToAdd = Cocktail::factory()->for($this->barMembership->bar)->create();

        $response = $this->putJson('/api/collections/' . $model->id . '/cocktails', [
            'cocktails' => [$newCocktailToAdd->id],
        ]);

        $response->assertNoContent();
    }

    public function test_shared_collection_allows_other_bar_member_to_sync_cocktails_but_not_manage_collection(): void
    {
        $collection = Collection::factory()->for($this->barMembership)->create([
            'name' => 'Shared',
            'is_bar_shared' => true,
        ]);
        $cocktail = Cocktail::factory()->for($this->barMembership->bar)->create();

        $otherUser = User::factory()->create();
        $otherUser->joinBarAs($this->barMembership->bar);
        $this->actingAs($otherUser);

        $this->putJson('/api/collections/' . $collection->id . '/cocktails', [
            'cocktails' => [$cocktail->id],
        ])->assertNoContent();

        $this->assertDatabaseHas('collection_cocktail', [
            'collection_id' => $collection->id,
            'cocktail_id' => $cocktail->id,
        ]);

        $this->putJson('/api/collections/' . $collection->id, [
            'name' => 'Renamed by other user',
        ])->assertForbidden();

        $this->deleteJson('/api/collections/' . $collection->id)
            ->assertForbidden();
    }

    public function test_sync_cocktails_in_collection_fails_for_unknown_cocktails(): void
    {
        $cocktailsInCollection = Cocktail::factory()->for($this->barMembership->bar)->count(3);

        $model = Collection::factory()
            ->for($this->barMembership)
            ->has($cocktailsInCollection)
            ->create([
                'name' => 'TEST',
                'description' => 'Description',
            ]);

        $newCocktailToAdd = Cocktail::factory()->create();

        $response = $this->putJson('/api/collections/' . $model->id . '/cocktails', [
            'cocktails' => [$newCocktailToAdd->id],
        ]);

        $response->assertUnprocessable();
    }
}
