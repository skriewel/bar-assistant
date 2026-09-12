<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use Brick\Math\RoundingMode;
use OpenApi\Attributes as OAT;
use Brick\Money\Context\DefaultContext;
use Kami\Cocktail\Models\CocktailIngredient;
use Kami\Cocktail\Models\ValueObjects\Price;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Kami\Cocktail\Models\CocktailPrice
 */
#[OAT\Schema(
    schema: 'CocktailPrice',
    description: 'Cocktail price resource',
    properties: [
        new OAT\Property(property: 'missing_prices_count', type: 'integer', example: 2, description: 'Number of ingredients that are missing defined prices in this category'),
        new OAT\Property(property: 'price_category', type: PriceCategoryResource::class),
        new OAT\Property(property: 'total_price', type: PriceResource::class, description: 'Total cocktail price, sum of `price_per_pour` amounts'),
        new OAT\Property(property: 'prices_per_ingredient', type: 'array', items: new OAT\Items(type: 'object', required: ['ingredient', 'price_per_unit', 'price_per_use', 'units'], properties: [
            new OAT\Property(property: 'ingredient', type: IngredientBasicResource::class),
            new OAT\Property(property: 'units', type: 'string', description: 'Units used for price calculation'),
            new OAT\Property(property: 'price_per_unit', type: PriceResource::class, description: 'Price per 1 unit of ingredient amount'),
            new OAT\Property(property: 'price_per_use', type: PriceResource::class, description: 'Price per cocktail ingredient part'),
        ]), description: 'Prices per each ingredient.'),
    ],
    required: ['missing_prices_count', 'price_category', 'total_price', 'prices_per_ingredient']
)]
class CocktailPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray($request)
    {
        $prices = $this->cocktail->ingredients->map(function (CocktailIngredient $cocktailIngredient) {
            $convertedPrices = $cocktailIngredient
                ->ingredient
                ->getPricesWithConvertedUnits($cocktailIngredient->units);

            $best = $convertedPrices
                ->map(function ($ingredientPrice) use ($cocktailIngredient) {
                    $pricePerUse = $ingredientPrice
                        ->getPricePerUnit($cocktailIngredient->units)
                        ->multipliedBy((string) $cocktailIngredient->amount);

                    return [
                        'ingredient_price' => $ingredientPrice,
                        'price_per_use' => $pricePerUse,
                    ];
                })
                ->sortBy(fn ($row) => $row['price_per_use']->getAmount()->toFloat())
                ->first();

            if ($best === null) {
                return null;
            }

            $ingredientPrice = $best['ingredient_price'];

            $pricePerUse = new Price(
                $best['price_per_use']->to(new DefaultContext(), RoundingMode::DOWN)
            );

            return [
                'units' => $ingredientPrice->getAmount()->units,
                'ingredient' => new IngredientBasicResource($cocktailIngredient->ingredient),
                'price_category' => new PriceCategoryResource($ingredientPrice->priceCategory),
                'price_per_unit' => new PriceResource(new Price(
                    $ingredientPrice
                        ->getPricePerUnit($cocktailIngredient->units)
                        ->to(new DefaultContext(), RoundingMode::DOWN)
                )),
                'price_per_use' => new PriceResource($pricePerUse),
                '_price_per_use' => $pricePerUse,
            ];
        })->filter()->values();

        $totalMoney = null;

        foreach ($prices as $row) {
            $money = $row['_price_per_use']->getMoney();
            $totalMoney = $totalMoney === null
                ? $money
                : $totalMoney->plus($money);
        }

        $prices = $prices->map(function (array $row) {
            unset($row['_price_per_use']);

            return $row;
        });

        return [
            'missing_prices_count' => $this->cocktail->ingredients->count() - $prices->count(),

            // Synthetic category so the existing Salt Rim UI still has a category title.
            'price_category' => [
                'id' => 0,
                'name' => 'Best available',
                'currency' => $totalMoney?->getCurrency()->getCurrencyCode() ?? 'EUR',
                'description' => 'Cheapest available price for each ingredient across all price categories',
            ],

            'total_price' => $totalMoney
                ? new PriceResource(new Price(
                    $totalMoney->to(new DefaultContext(), RoundingMode::DOWN)
                ))
                : null,

            'prices_per_ingredient' => $prices,
        ];
    }
}
