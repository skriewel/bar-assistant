<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use Kami\Cocktail\Models\Cocktail;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PublicationCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_publications_dry_run_does_not_write_and_apply_moves_source(): void
    {
        $membership = $this->setupBarMembership();
        $cocktail = Cocktail::factory()->recycle($membership->bar, $membership->user)->create([
            'source' => 'The PDT Cocktail Book',
            'publication' => null,
        ]);

        $this->artisan('bar:migrate-cocktail-publications', ['--dry-run' => true])
            ->assertExitCode(0);

        $cocktail->refresh();
        $this->assertSame('The PDT Cocktail Book', $cocktail->source);
        $this->assertNull($cocktail->publication);

        $this->artisan('bar:migrate-cocktail-publications')
            ->assertExitCode(0);

        $cocktail->refresh();
        $this->assertNull($cocktail->source);
        $this->assertSame('The PDT Cocktail Book', $cocktail->publication);
    }

    public function test_cleanup_publications_dry_run_does_not_write_and_apply_extracts_year(): void
    {
        $membership = $this->setupBarMembership();
        $cocktail = Cocktail::factory()->recycle($membership->bar, $membership->user)->create([
            'publication' => 'The PDT Cocktail Book (2009)',
            'year' => null,
        ]);

        $this->artisan('bar:cleanup-cocktail-publications', ['--dry-run' => true])
            ->assertExitCode(0);

        $cocktail->refresh();
        $this->assertSame('The PDT Cocktail Book (2009)', $cocktail->publication);
        $this->assertNull($cocktail->year);

        $this->artisan('bar:cleanup-cocktail-publications')
            ->assertExitCode(0);

        $cocktail->refresh();
        $this->assertSame('The PDT Cocktail Book', $cocktail->publication);
        $this->assertSame(2009, (int) $cocktail->year);
    }
}
