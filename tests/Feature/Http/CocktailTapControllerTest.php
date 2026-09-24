<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\Cocktail;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CocktailTapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_ability_cannot_mutate_taps_but_write_ability_can(): void
    {
        $membership = $this->setupBarMembership();
        $cocktail = Cocktail::factory()
            ->recycle($membership->bar, $membership->user)
            ->create();

        $headers = ['Bar-Assistant-Bar-Id' => (string) $membership->bar_id];

        $this->actingAs($membership->user, abilities: ['cocktails.read']);

        $this->getJson('/api/cocktails/'.$cocktail->id.'/taps', $headers)
            ->assertOk();

        $this->postJson('/api/cocktails/'.$cocktail->id.'/taps', ['date' => '2026-09-24'], $headers)
            ->assertForbidden();

        $this->actingAs($membership->user, abilities: ['cocktails.write']);

        $create = $this->postJson('/api/cocktails/'.$cocktail->id.'/taps', ['date' => '2026-09-24'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-09-24');

        $tapId = $create->json('data.id');

        $this->patchJson('/api/cocktails/'.$cocktail->id.'/taps/'.$tapId, ['date' => '2026-09-23'], $headers)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-23');

        $this->deleteJson('/api/cocktails/'.$cocktail->id.'/taps/'.$tapId, [], $headers)
            ->assertNoContent();
    }

    public function test_invalid_tap_date_filters_are_rejected(): void
    {
        $membership = $this->setupBarMembership();
        $this->actingAs($membership->user, abilities: ['cocktails.read']);

        $headers = ['Bar-Assistant-Bar-Id' => (string) $membership->bar_id];

        $this->getJson('/api/cocktails?filter[tapped_after]=not-a-date', $headers)
            ->assertUnprocessable();

        $this->getJson('/api/cocktails?filter[tapped_before]=2026-99-99', $headers)
            ->assertUnprocessable();
    }

    public function test_taps_are_isolated_by_bar_membership(): void
    {
        $membership = $this->setupBarMembership();
        $cocktail = Cocktail::factory()
            ->recycle($membership->bar, $membership->user)
            ->create();

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser, abilities: ['cocktails.write']);

        $this->postJson(
            '/api/cocktails/'.$cocktail->id.'/taps',
            ['date' => '2026-09-24'],
            ['Bar-Assistant-Bar-Id' => (string) $membership->bar_id],
        )->assertForbidden();
    }
}
