<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use Brick\Money\Money;
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
        new OAT\Property(property: 'missing_prices_count', type: 'integer', example: 2, description: 'Number of ingredients that are missing usable prices'),
        new OAT\Property(property: 'price_category', type: PriceCategoryResource::class),
        new OAT\Property(property: 'total_price', type: PriceResource::class, description: 'Total cocktail price using the cheapest available price for each ingredient'),
        new OAT\Property(property: 'prices_per_ingredient', type: 'array', items: new OAT\Items(type: 'object', required: ['ingredient', 'price_per_unit', 'price_per_use', 'units'], properties: [
            new OAT\Property(property: 'ingredient', type: IngredientBasicResource::class),
            new OAT\Property(property: 'units', type: 'string', description: 'Units used for price calculation'),
            new OAT\Property(property: 'price_category', type: PriceCategoryResource::class),
            new OAT\Property(property: 'price_per_unit', type: PriceResource::class, description: 'Price per 1 unit of ingredient amount'),
            new OAT\Property(property: 'price_per_use', type: PriceResource::class, description: 'Price per cocktail ingredient part'),
        ]), description: 'Cheapest available price for each ingredient.'),
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
        $currency = $this->priceCategory->currency;

        $prices = collect();
        $totalPrice = Money::of(0, $currency)->toRational();

        foreach ($this->cocktail->ingredients as $cocktailIngredient) {
            /** @var CocktailIngredient $cocktailIngredient */
            $minIngredientPrice = $cocktailIngredient->getMinConvertedPrice($currency);
            $pricePerUse = $cocktailIngredient->getConvertedBestPricePerUse($currency);

            if ($minIngredientPrice === null || $pricePerUse === null) {
                continue;
            }

            $totalPrice = $totalPrice->plus($pricePerUse);

            $prices->push([
                'units' => $minIngredientPrice->getAmount()->units,
                'ingredient' => new IngredientBasicResource($cocktailIngredient->ingredient),
                'price_category' => new PriceCategoryResource($minIngredientPrice->priceCategory),
                'price_per_unit' => new PriceResource(
                    Price::fromMoney(
                        $minIngredientPrice
                            ->getPricePerUnit($cocktailIngredient->units)
                            ->to(new DefaultContext(), RoundingMode::DOWN)
                    )
                ),
                'price_per_use' => new PriceResource(
                    Price::fromMoney(
                        $pricePerUse->to(new DefaultContext(), RoundingMode::DOWN)
                    )
                ),
            ]);
        }

        return [
            'missing_prices_count' => $this->cocktail->ingredients->count() - $prices->count(),
            'price_category' => new PriceCategoryResource($this->priceCategory),
            'total_price' => new PriceResource(
                Price::fromMoney(
                    $totalPrice->to(new DefaultContext(), RoundingMode::DOWN)
                )
            ),
            'prices_per_ingredient' => $prices,
        ];
    }
}
