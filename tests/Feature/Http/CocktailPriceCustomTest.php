<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Ingredient;
use Kami\Cocktail\Models\PriceCategory;
use Kami\Cocktail\Models\IngredientPrice;
use Kami\Cocktail\Models\CocktailIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CocktailPriceCustomTest extends TestCase
{
    use RefreshDatabase;

    public function test_best_available_price_uses_lowest_price_per_unit_across_categories(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create(['name' => 'Test spirit']);
        $cocktail = Cocktail::factory()->recycle($membership->bar, $membership->user)->create();

        CocktailIngredient::factory()->for($cocktail)->for($ingredient)->create([
            'amount' => 50,
            'amount_max' => null,
            'units' => 'ml',
            'optional' => false,
        ]);

        $categoryA = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Category A',
            'currency' => 'EUR',
        ]);
        $categoryB = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'Category B',
            'currency' => 'EUR',
        ]);

        IngredientPrice::factory()->for($ingredient)->for($categoryA, 'priceCategory')->create([
            'price' => 3000,
            'amount' => 700,
            'units' => 'ml',
        ]);
        IngredientPrice::factory()->for($ingredient)->for($categoryB, 'priceCategory')->create([
            'price' => 3500,
            'amount' => 1000,
            'units' => 'ml',
        ]);

        $response = $this->getJson(
            '/api/cocktails/' . $cocktail->id . '/prices',
            ['Bar-Assistant-Bar-Id' => $membership->bar_id]
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.price_category.name', 'Best available');
        $response->assertJsonPath('data.0.missing_prices_count', 0);
        $response->assertJsonPath('data.0.prices_per_ingredient.0.price_category.name', 'Category B');
        $response->assertJsonPath('data.0.prices_per_ingredient.0.price_per_use.price', 1.75);
        $response->assertJsonPath('data.0.total_price.price', 1.75);
    }
    public function test_best_available_price_skips_categories_with_other_currencies(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user);

        $ingredient = Ingredient::factory()->for($membership->bar)->create(['name' => 'Test spirit']);
        $cocktail = Cocktail::factory()->recycle($membership->bar, $membership->user)->create();

        CocktailIngredient::factory()->for($cocktail)->for($ingredient)->create([
            'amount' => 50,
            'amount_max' => null,
            'units' => 'ml',
            'optional' => false,
        ]);

        $eurCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'EUR source',
            'currency' => 'EUR',
        ]);
        $usdCategory = PriceCategory::factory()->for($membership->bar)->create([
            'name' => 'USD source',
            'currency' => 'USD',
        ]);

        IngredientPrice::factory()->for($ingredient)->for($eurCategory, 'priceCategory')->create([
            'price' => 3000,
            'amount' => 1000,
            'units' => 'ml',
        ]);
        IngredientPrice::factory()->for($ingredient)->for($usdCategory, 'priceCategory')->create([
            'price' => 100,
            'amount' => 1000,
            'units' => 'ml',
        ]);

        $response = $this->getJson(
            '/api/cocktails/' . $cocktail->id . '/prices',
            ['Bar-Assistant-Bar-Id' => $membership->bar_id]
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.price_category.name', 'Best available');
        $response->assertJsonPath('data.0.price_category.currency', 'EUR');
        $response->assertJsonPath('data.0.prices_per_ingredient.0.price_category.name', 'EUR source');
        $response->assertJsonPath('data.0.total_price.price', 1.5);
    }

}
