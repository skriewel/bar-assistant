<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Filters;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kami\Cocktail\Models\Cocktail;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Kami\Cocktail\Models\BarMembership;
use Spatie\QueryBuilder\AllowedInclude;
use Kami\Cocktail\Services\CocktailService;

/**
 * @extends \Spatie\QueryBuilder\QueryBuilder<Cocktail>
 */
final class CocktailQueryFilter extends QueryBuilder
{
    public function __construct(CocktailService $cocktailRepo)
    {
        parent::__construct(Cocktail::query());

        $barMembership = $this->request->user()->getBarMembership(bar()->id);
        if (!$barMembership) {
            abort(403, 'No bar membership');
        }

        $this
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::custom('name', new FilterNameSearch()),
                AllowedFilter::partial('publication'),
                AllowedFilter::partial('ingredient_name', 'ingredients.ingredient.name'),
                AllowedFilter::exact('ingredient_substitute_id', 'ingredients.substitutes.ingredient.id'),
                AllowedFilter::callback('ingredient_id', function ($query, $value) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->where(function ($q) use ($value) {
                        $q->whereIn('ci.ingredient_id', $value)->orWhereIn('cis.ingredient_id', $value);
                    });
                }),
                AllowedFilter::exact('tag_id', 'tags.id'),
                AllowedFilter::exact('created_user_id'),
                AllowedFilter::exact('author'),
                AllowedFilter::exact('glass_id'),
                AllowedFilter::exact('cocktail_method_id'),
                AllowedFilter::callback('collection_id', function ($query, $value) use ($barMembership) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->whereHas('collections', function ($query) use ($value, $barMembership) {
                        $query->whereIn('collections.id', $value)
                            ->join('bar_memberships', 'bar_memberships.id', '=', 'collections.bar_membership_id')
                            ->where(function ($query) use ($barMembership) {
                                $query->where('collections.bar_membership_id', $barMembership->id)->orWhere('is_bar_shared', true);
                            });
                    });
                }),
                AllowedFilter::callback('favorites', function ($query, $value) use ($barMembership) {
                    if ($value === true) {
                        $query->userFavorites([$barMembership->id]);
                    }
                }),
                AllowedFilter::callback('favorited_by_user', function ($query, $value) use ($barMembership) {
                    if (!is_array($value)) {
                        $rawValues = [$value];
                    } else {
                        $rawValues = $value;
                    }

                    $userIds = array_values(array_unique(array_filter(
                        array_map(fn ($v) => (int) $v, $rawValues),
                        fn ($v) => $v > 0,
                    )));

                    if ($userIds === []) {
                        return;
                    }

                    $resolvedBarMembershipIds = BarMembership::query()
                        ->whereIn('user_id', $userIds)
                        ->where('bar_id', $barMembership->bar_id)
                        ->pluck('id')
                        ->all();

                    $query->userFavorites($resolvedBarMembershipIds);
                }),
                AllowedFilter::callback('bar_shelf', function ($query, $value) {
                    if ($value === true) {
                        $query->whereIn('cocktails.id', bar()->getShelfCocktailsOnce());
                    }
                }),
                AllowedFilter::callback('locked_bar_cocktails', function ($query, $value) {
                    if ($value === true) {
                        $query->whereNotIn('cocktails.id', bar()->getShelfCocktailsOnce());
                    }
                }),
                AllowedFilter::callback('user_shelves', function ($query, $value) use ($cocktailRepo, $barMembership) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $ingredients = DB::table('bar_memberships')
                        ->select('user_ingredients.ingredient_id')
                        ->join('user_ingredients', 'user_ingredients.bar_membership_id', '=', 'bar_memberships.id')
                        ->whereIn('bar_memberships.user_id', $value)
                        ->where('bar_memberships.bar_id', bar()->id)
                        ->get();

                    $query->whereIn('cocktails.id', $cocktailRepo->getCocktailsByIngredients(
                        $ingredients->pluck('ingredient_id')->toArray(),
                        $barMembership->bar_id,
                    ));
                }),
                AllowedFilter::callback('shelf_ingredients', function ($query, $value) use ($cocktailRepo, $barMembership) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->whereIn('cocktails.id', $cocktailRepo->getCocktailsByIngredients(
                        $value,
                        $barMembership->bar_id,
                    ));
                }),
                AllowedFilter::callback('is_public', function ($query, $value) {
                    if ($value === true) {
                        $query->whereNotNull('public_id');
                    }
                }),
                AllowedFilter::callback('tapped_after', function ($query, $value) use ($barMembership) {
                    $date = Validator::make(
                        ['date' => $value],
                        ['date' => ['required', 'date_format:Y-m-d']],
                    )->validate()['date'];

                    $query->whereRaw(
                        '(SELECT MAX(ct.tapped_on) FROM cocktail_taps ct WHERE ct.cocktail_id = cocktails.id AND ct.bar_membership_id = ?) >= ?',
                        [$barMembership->id, $date],
                    );
                }),
                AllowedFilter::callback('tapped_before', function ($query, $value) use ($barMembership) {
                    $date = Validator::make(
                        ['date' => $value],
                        ['date' => ['required', 'date_format:Y-m-d']],
                    )->validate()['date'];

                    $query->whereRaw(
                        '(SELECT MAX(ct.tapped_on) FROM cocktail_taps ct WHERE ct.cocktail_id = cocktails.id AND ct.bar_membership_id = ?) <= ?',
                        [$barMembership->id, $date],
                    );
                }),
                AllowedFilter::callback('never_tapped', function ($query, $value) use ($barMembership) {
                    if ($value === true || $value === 'true' || $value === '1' || $value === 1) {
                        $query->whereNotExists(function ($subquery) use ($barMembership) {
                            $subquery->selectRaw('1')
                                ->from('cocktail_taps as ct')
                                ->whereColumn('ct.cocktail_id', 'cocktails.id')
                                ->where('ct.bar_membership_id', $barMembership->id);
                        });
                    }
                }),
                AllowedFilter::callback('user_rating_min', function ($query, $value) {
                    if ($value === 'none') {
                        $query->whereNull('user_rating');
                        return;
                    }
                    $query->where('user_rating', '>=', (float) $value);
                }),
                AllowedFilter::callback('user_rating_max', function ($query, $value) {
                    $query->where('user_rating', '<=', (float) $value);
                }),
                AllowedFilter::callback('average_rating_min', function ($query, $value) {
                    $query->where('average_rating', '>=', (float) $value);
                }),
                AllowedFilter::callback('average_rating_max', function ($query, $value) {
                    $query->where('average_rating', '<=', (float) $value);
                }),
                AllowedFilter::callback('abv_min', function ($query, $value) {
                    $query->where('abv', '>=', $value);
                }),
                AllowedFilter::callback('abv_max', function ($query, $value) {
                    $query->where('abv', '<=', $value);
                }),
                AllowedFilter::callback('year_min', function ($query, $value) {
                    $query->where('cocktails.year', '>=', (int) $value);
                }),
                AllowedFilter::callback('year_max', function ($query, $value) {
                    $query->where('cocktails.year', '<=', (int) $value);
                }),
                AllowedFilter::callback('main_ingredient_id', function ($query, $value) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->whereIn('ci.ingredient_id', $value)->where('sort', '=', 1);
                }),
                AllowedFilter::callback('total_ingredients', function ($query, $value) {
                    if ($value === 'max3') {
                        $query->having('total_ingredients', '<=', 3);
                        return;
                    }
                    $query->having('total_ingredients', '>=', (int) $value);
                }),
                AllowedFilter::callback('missing_ingredients', function ($query, $value) {
                    if ((int) $value >= 3) {
                        $query->having('missing_ingredients', '>=', (int) $value);
                    } else {
                        $query->having('missing_ingredients', (int) $value);
                    }
                }),
                AllowedFilter::callback('missing_bar_ingredients', function ($query, $value) {
                    if ((int) $value >= 3) {
                        $query->having('missing_bar_ingredients', '>=', (int) $value);
                    } else {
                        $query->having('missing_bar_ingredients', (int) $value);
                    }
                }),
                AllowedFilter::callback('specific_ingredients', function ($query, $value) use ($barMembership) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->whereIn('cocktails.id', function ($query) use ($barMembership, $value) {
                        $query
                            ->select('cocktails.id')
                            ->from('cocktails')
                            ->where('cocktails.bar_id', $barMembership->bar_id)
                            ->join('cocktail_ingredients', 'cocktail_ingredients.cocktail_id', '=', 'cocktails.id')
                            ->whereIn('cocktail_ingredients.ingredient_id', $value)
                            ->groupBy('cocktails.id')
                            ->havingRaw('COUNT(DISTINCT cocktail_ingredients.ingredient_id) >= ?', [count($value)]);
                    });
                }),
                AllowedFilter::callback('ignore_ingredients', function ($query, $value) use ($barMembership) {
                    if (!is_array($value)) {
                        $value = [$value];
                    }

                    $query->whereNotIn('cocktails.id', function ($query) use ($barMembership, $value) {
                        $query
                            ->select('cocktails.id')
                            ->from('cocktails')
                            ->where('cocktails.bar_id', $barMembership->bar_id)
                            ->join('cocktail_ingredients', 'cocktail_ingredients.cocktail_id', '=', 'cocktails.id')
                            ->whereIn('cocktail_ingredients.ingredient_id', $value);
                    });
                }),
                AllowedFilter::exact('parent_cocktail_id'),
            ])
            ->defaultSort('name')
            ->allowedSorts([
                'name',
                'created_at',
                'average_rating',
                'user_rating',
                'abv',
                'year',
                'total_ingredients',
                'missing_ingredients',
                'missing_bar_ingredients',
                'reviews_count',
                AllowedSort::callback('favorited_at', function ($query, bool $descending) use ($barMembership) {
                    $direction = $descending ? 'DESC' : 'ASC';
                    $query->leftJoin('cocktail_favorites AS cf', 'cf.cocktail_id', '=', 'cocktails.id')
                        ->where('cf.bar_membership_id', $barMembership->id)
                        ->orderBy('cf.updated_at', $direction);
                }),
                AllowedSort::callback('last_tapped_on', function ($query, bool $descending) use ($barMembership) {
                    $direction = $descending ? 'DESC' : 'ASC';
                    $query->orderByRaw(
                        '(SELECT MAX(ct.tapped_on) FROM cocktail_taps ct WHERE ct.cocktail_id = cocktails.id AND ct.bar_membership_id = ?) ' . $direction,
                        [$barMembership->id],
                    );
                }),
                AllowedSort::callback('random', function ($query) {
                    $query->inRandomOrder();
                }),
            ])
            ->allowedIncludes([
                'glass',
                'method',
                'user',
                'utensils',
                'createdUser',
                'updatedUser',
                'images',
                'tags',
                'ingredients.ingredient',
                'ratings',
                AllowedInclude::callback('navigation', fn ($q) => $q),
            ])
            ->selectRaw(implode(', ', [
                'cocktails.*',
                '(SELECT COUNT(*) FROM cocktail_ingredients WHERE cocktail_ingredients.cocktail_id = cocktails.id) AS total_ingredients',
                '(SELECT COUNT(*) FROM cocktail_ingredients WHERE cocktail_ingredients.cocktail_id = cocktails.id) - COUNT(ui.ingredient_id) AS missing_ingredients',
                '(SELECT COUNT(*) FROM cocktail_ingredients WHERE cocktail_ingredients.cocktail_id = cocktails.id) - COUNT(bi.ingredient_id) AS missing_bar_ingredients',
                '(SELECT COUNT(*) FROM cocktail_reviews WHERE cocktail_reviews.cocktail_id = cocktails.id) AS reviews_count',
            ]))
            ->leftJoin('cocktail_ingredients AS ci', 'ci.cocktail_id', '=', 'cocktails.id')
            ->leftJoin('cocktail_ingredient_substitutes AS cis', 'cis.cocktail_ingredient_id', '=', 'ci.id')
            ->leftJoin('user_ingredients AS ui', function ($query) use ($barMembership) {
                $query->on('ui.ingredient_id', '=', 'ci.ingredient_id')->where('ui.bar_membership_id', $barMembership->id);
            })
            ->leftJoin('bar_ingredients AS bi', function ($query) {
                $query->on('bi.ingredient_id', '=', 'ci.ingredient_id');
            })
            ->groupBy('cocktails.id')
            ->filterByBar('cocktails')
            ->with(['bar.shelfIngredients', 'ingredients.ingredient.bar'])
            ->withRatings($this->request->user()->id);
    }
}
