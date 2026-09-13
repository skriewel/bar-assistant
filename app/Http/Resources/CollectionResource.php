<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Resources;

use OpenApi\Attributes as OAT;
use Illuminate\Http\Resources\Json\JsonResource;

#[OAT\Schema(
    schema: 'Collection',
    description: 'Cocktail collection resource',
    properties: [
        new OAT\Property(property: 'id', type: 'integer'),
        new OAT\Property(property: 'name', type: 'string'),
        new OAT\Property(property: 'description', type: 'string', nullable: true),
        new OAT\Property(property: 'is_bar_shared', type: 'boolean'),
        new OAT\Property(property: 'is_collaborative', type: 'boolean'),
        new OAT\Property(property: 'is_owned_by_user', type: 'boolean'),
        new OAT\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OAT\Property(property: 'created_user', ref: UserResource::class, nullable: true),
        new OAT\Property(property: 'cocktails', type: 'array', items: new OAT\Items(ref: CocktailBasicResource::class)),
    ],
    required: ['id', 'name', 'description', 'is_bar_shared', 'is_collaborative', 'is_owned_by_user', 'created_at']
)]
class CollectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_bar_shared' => $this->is_bar_shared,
            'is_collaborative' => $this->is_collaborative,
            'is_owned_by_user' => $request->user()->memberships->contains('id', $this->bar_membership_id),
            'created_at' => $this->created_at,
            'created_user' => $this->whenLoaded('barMembership', fn () => new UserResource($this->barMembership->user)),
            'cocktails' => CocktailBasicResource::collection($this->whenLoaded('cocktails')),
        ];
    }
}
