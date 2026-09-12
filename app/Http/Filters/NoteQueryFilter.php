<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Filters;

use Kami\Cocktail\Models\Note;
use Kami\Cocktail\Models\Cocktail;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

/**
 * @extends \Spatie\QueryBuilder\QueryBuilder<Note>
 */
final class NoteQueryFilter extends QueryBuilder
{
    public function __construct()
    {
        parent::__construct(
            Note::query()
                ->where('noteable_type', Cocktail::class)
                ->whereExists(function ($query) {
                    $query
                        ->selectRaw('1')
                        ->from('cocktails')
                        ->whereColumn('cocktails.id', 'notes.noteable_id')
                        ->where('cocktails.bar_id', bar()->id);
                })
        );

        $this
            ->allowedFilters([
                AllowedFilter::callback('cocktail_id', function ($query, $value) {
                    $query
                        ->where('noteable_type', Cocktail::class)
                        ->where('noteable_id', $value);
                }),
            ])
            ->defaultSort('created_at')
            ->allowedSorts('created_at');
    }
}
