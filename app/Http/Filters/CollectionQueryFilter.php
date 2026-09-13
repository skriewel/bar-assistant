<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Filters;

use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Kami\Cocktail\Models\Collection as ItemsCollection;

final class CollectionQueryFilter extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(ItemsCollection::query());
        $barMembership = $this->request->user()->getBarMembership(bar()->id);

        $this
            ->allowedFilters([
                AllowedFilter::exact('id'),
                AllowedFilter::partial('name'),
                AllowedFilter::scope('cocktail_id'),
            ])
            ->defaultSort('name')
            ->allowedSorts('name', 'created_at')
            ->allowedIncludes('cocktails')
            ->where(function ($query) use ($barMembership): void {
                $query
                    ->where('bar_membership_id', $barMembership->id)
                    ->orWhere(function ($query) use ($barMembership): void {
                        $query
                            ->where('is_bar_shared', true)
                            ->where('is_collaborative', true)
                            ->whereHas('barMembership', function ($membershipQuery) use ($barMembership): void {
                                $membershipQuery->where('bar_id', $barMembership->bar_id);
                            });
                    });
            });
    }
}
