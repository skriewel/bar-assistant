<?php

declare(strict_types=1);

namespace Kami\Cocktail\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\Tag;

class MigrateCocktailPublications extends Command
{
    protected $signature = 'bar:migrate-cocktail-publications {--dry-run : Show planned changes without writing them}';

    protected $description = 'Move Source tags and non-web source values into cocktail publications';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'cocktails' => 0,
            'publication_from_tag' => 0,
            'publication_from_source' => 0,
            'source_cleared' => 0,
            'source_tags_detached' => 0,
            'orphan_source_tags_deleted' => 0,
            'conflicts' => 0,
        ];
        $conflicts = [];

        if ($dryRun) {
            $this->info('DRY RUN - no data will be changed.');
        }

        Cocktail::query()
            ->with('tags')
            ->orderBy('id')
            ->chunkById(250, function ($cocktails) use ($dryRun, &$stats, &$conflicts): void {
                foreach ($cocktails as $cocktail) {
                    $stats['cocktails']++;
                    $publication = $this->clean($cocktail->publication);
                    $source = $this->clean($cocktail->source);
                    $sourceTags = $cocktail->tags->filter(
                        fn (Tag $tag): bool => preg_match('/^Source:\s+(.+)$/i', trim($tag->name)) === 1
                    );

                    $tagPublications = $sourceTags
                        ->map(fn (Tag $tag): string => trim((string) preg_replace('/^Source:\s+/i', '', trim($tag->name))))
                        ->filter(fn (string $value): bool => $value !== '')
                        ->unique(fn (string $value): string => mb_strtolower($value))
                        ->values();

                    $canDetachSourceTags = true;

                    if ($tagPublications->count() > 1) {
                        $canDetachSourceTags = false;
                        $this->addConflict($conflicts, $stats, $cocktail, 'multiple Source tags', $tagPublications->implode(' | '));
                    } elseif ($tagPublications->count() === 1) {
                        $candidate = $tagPublications->first();
                        if ($publication === null) {
                            $publication = $candidate;
                            $stats['publication_from_tag']++;
                        } elseif (!$this->sameText($publication, $candidate)) {
                            $canDetachSourceTags = false;
                            $this->addConflict($conflicts, $stats, $cocktail, 'publication differs from Source tag', $publication . ' <> ' . $candidate);
                        }
                    }

                    if ($source !== null && !$this->isWebUrl($source)) {
                        if ($publication === null) {
                            $publication = $source;
                            $source = null;
                            $stats['publication_from_source']++;
                            $stats['source_cleared']++;
                        } elseif ($this->sameText($publication, $source)) {
                            $source = null;
                            $stats['source_cleared']++;
                        } else {
                            $this->addConflict($conflicts, $stats, $cocktail, 'non-web source differs from publication', $publication . ' <> ' . $source);
                        }
                    }

                    $changed = $this->clean($cocktail->publication) !== $publication || $this->clean($cocktail->source) !== $source;
                    if ($changed && !$dryRun) {
                        $cocktail->publication = $publication;
                        $cocktail->source = $source;
                        $cocktail->save();
                    }

                    if ($canDetachSourceTags && $sourceTags->isNotEmpty()) {
                        $stats['source_tags_detached'] += $sourceTags->count();
                        if (!$dryRun) {
                            $cocktail->tags()->detach($sourceTags->pluck('id')->all());
                        }
                    }
                }
            });

        $orphanSourceTags = Tag::query()
            ->where('name', 'like', 'Source: %')
            ->whereDoesntHave('cocktails')
            ->get();
        $stats['orphan_source_tags_deleted'] = $orphanSourceTags->count();
        if (!$dryRun && $orphanSourceTags->isNotEmpty()) {
            Tag::query()->whereIn('id', $orphanSourceTags->pluck('id')->all())->delete();
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, $name) => [$name, $count])->values()->all());

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Conflicts were left unchanged and require manual review:');
            $this->table(['ID', 'Cocktail', 'Reason', 'Values'], $conflicts);
        }

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }

    private function addConflict(array &$conflicts, array &$stats, Cocktail $cocktail, string $reason, string $values): void
    {
        $stats['conflicts']++;
        $conflicts[] = [$cocktail->id, $cocktail->name, $reason, $values];
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function sameText(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function isWebUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
