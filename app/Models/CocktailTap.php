<?php

declare(strict_types=1);

namespace Kami\Cocktail\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CocktailTap extends BaseModel
{
    protected $casts = [
        'tapped_on' => 'date:Y-m-d',
    ];

    protected $fillable = [
        'cocktail_id',
        'bar_membership_id',
        'tapped_on',
    ];

    public function cocktail(): BelongsTo
    {
        return $this->belongsTo(Cocktail::class);
    }

    public function barMembership(): BelongsTo
    {
        return $this->belongsTo(BarMembership::class);
    }
}
