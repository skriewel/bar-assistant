<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Tests\TestCase;
use Kami\Cocktail\Models\Bar;
use Kami\Cocktail\Models\User;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\BarMembership;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private Bar $bar;

    public function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->bar = $this->setupBar();
        $this->withHeader('Bar-Assistant-Bar-Id', (string) $this->bar->id);
    }

    public function test_list_notes_response(): void
    {
        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $cocktail->addNote('Test note 1', auth('sanctum')->user()->id);
        $cocktail->addNote('Test note 2', auth('sanctum')->user()->id);
        $cocktail->addNote('Test note 3', auth('sanctum')->user()->id);

        $response = $this->getJson('/api/notes');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_list_notes_by_cocktail_response(): void
    {
        $cocktail1 = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $cocktail2 = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $cocktail1->addNote('Test note 1', auth('sanctum')->user()->id);
        $cocktail2->addNote('Test note 2', auth('sanctum')->user()->id);

        $response = $this->getJson('/api/notes?filter[cocktail_id]=' . $cocktail1->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_list_notes_only_returns_notes_from_active_bar(): void
    {
        $otherUser = User::factory()->create();
        $otherBar = Bar::factory()->create(['created_user_id' => $otherUser->id]);

        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $otherCocktail = Cocktail::factory()->create(['bar_id' => $otherBar->id]);

        $cocktail->addNote('Visible note', auth('sanctum')->user()->id);
        $otherCocktail->addNote('Hidden note', $otherUser->id);

        $response = $this->getJson('/api/notes');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.note', auth('sanctum')->user()->name . ': Visible note');
    }

    public function test_show_note_response(): void
    {
        $user = auth('sanctum')->user();
        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $note = $cocktail->addNote('Test note', $user->id);

        $response = $this->getJson('/api/notes/' . $note->id);

        $response->assertOk();
        $response->assertJson(
            fn (AssertableJson $json) =>
            $json
                ->has('data.id')
                ->where('data.note', $user->name . ': Test note')
                ->where('data.user_id', $user->id)
                ->has('data.created_at')
                ->etc()
        );
    }

    public function test_show_note_from_other_member_of_same_bar_response(): void
    {
        $otherUser = User::factory()->create();
        BarMembership::factory()->create([
            'bar_id' => $this->bar->id,
            'user_id' => $otherUser->id,
        ]);

        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $note = $cocktail->addNote('Shared note', $otherUser->id);

        $response = $this->getJson('/api/notes/' . $note->id);

        $response->assertOk();
        $response->assertJsonPath('data.note', $otherUser->name . ': Shared note');
        $response->assertJsonPath('data.user_id', $otherUser->id);
    }

    public function test_show_note_from_other_bar_is_forbidden(): void
    {
        $otherUser = User::factory()->create();
        $otherBar = Bar::factory()->create(['created_user_id' => $otherUser->id]);
        $cocktail = Cocktail::factory()->create(['bar_id' => $otherBar->id]);
        $note = $cocktail->addNote('Hidden note', $otherUser->id);

        $response = $this->getJson('/api/notes/' . $note->id);

        $response->assertForbidden();
    }

    public function test_save_cocktail_note_response(): void
    {
        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $response = $this->postJson('/api/notes/', [
            'note' => 'A new note',
            'resource_id' => $cocktail->id,
            'resource' => 'cocktail',
        ]);

        $response->assertCreated();
    }

    public function test_save_cocktail_note_from_other_bar_is_not_found(): void
    {
        $otherUser = User::factory()->create();
        $otherBar = Bar::factory()->create(['created_user_id' => $otherUser->id]);
        $cocktail = Cocktail::factory()->create([
            'bar_id' => $otherBar->id,
            'created_user_id' => $otherUser->id,
        ]);

        $response = $this->postJson('/api/notes/', [
            'note' => 'A new note',
            'resource_id' => $cocktail->id,
            'resource' => 'cocktail',
        ]);

        $response->assertNotFound();
    }

    public function test_delete_cocktail_note_response(): void
    {
        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $note = $cocktail->addNote('Test note', auth('sanctum')->user()->id);

        $response = $this->deleteJson('/api/notes/' . $note->id);

        $response->assertNoContent();
    }

    public function test_delete_cocktail_note_from_other_member_is_forbidden(): void
    {
        $otherUser = User::factory()->create();
        BarMembership::factory()->create([
            'bar_id' => $this->bar->id,
            'user_id' => $otherUser->id,
        ]);

        $cocktail = Cocktail::factory()->create(['bar_id' => $this->bar->id]);
        $note = $cocktail->addNote('Shared note', $otherUser->id);

        $response = $this->deleteJson('/api/notes/' . $note->id);

        $response->assertForbidden();
    }
}
