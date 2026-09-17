<?php

declare(strict_types=1);

namespace Kami\Cocktail\Console\Commands;

use Illuminate\Console\Command;
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
            'source_urls_normalized' => 0,
            'publication_values_normalized' => 0,
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
                    $originalPublication = $this->clean($cocktail->publication);
                    $publication = $this->normalizePublication($originalPublication);
                    $source = $this->clean($cocktail->source);

                    if ($publication !== $originalPublication) {
                        $stats['publication_values_normalized']++;
                    }

                    $sourceTags = $cocktail->tags->filter(
                        fn (Tag $tag): bool => preg_match('/^Source:\s+(.+)$/i', trim($tag->name)) === 1
                    );

                    $tagPublications = $sourceTags
                        ->map(fn (Tag $tag): string => trim((string) preg_replace('/^Source:\s+/i', '', trim($tag->name))))
                        ->filter(fn (string $value): bool => $value !== '')
                        ->map(fn (string $value): string => $this->normalizePublication($value) ?? $value)
                        ->unique(fn (string $value): string => mb_strtolower($value))
                        ->values();

                    $canDetachSourceTags = true;

                    if ($tagPublications->count() > 1) {
                        $resolved = $this->resolvePublicationCandidates($tagPublications->all());
                        if ($resolved === null) {
                            $canDetachSourceTags = false;
                            $this->addConflict($conflicts, $stats, $cocktail, 'multiple Source tags', $tagPublications->implode(' | '));
                        } elseif ($publication === null) {
                            $publication = $resolved;
                            $stats['publication_from_tag']++;
                        } else {
                            $merged = $this->resolvePublicationPair($publication, $resolved);
                            if ($merged === null) {
                                $canDetachSourceTags = false;
                                $this->addConflict($conflicts, $stats, $cocktail, 'publication differs from Source tags', $publication . ' <> ' . $resolved);
                            } else {
                                $publication = $merged;
                            }
                        }
                    } elseif ($tagPublications->count() === 1) {
                        $candidate = $tagPublications->first();
                        if ($publication === null) {
                            $publication = $candidate;
                            $stats['publication_from_tag']++;
                        } else {
                            $merged = $this->resolvePublicationPair($publication, $candidate);
                            if ($merged === null) {
                                $canDetachSourceTags = false;
                                $this->addConflict($conflicts, $stats, $cocktail, 'publication differs from Source tag', $publication . ' <> ' . $candidate);
                            } else {
                                $publication = $merged;
                            }
                        }
                    }

                    if ($source !== null) {
                        $webUrl = $this->normalizeWebUrl($source);
                        if ($webUrl !== null) {
                            if ($webUrl !== $source) {
                                $source = $webUrl;
                                $stats['source_urls_normalized']++;
                            }
                        } else {
                            $sourcePublication = $this->normalizePublication($source);
                            if ($publication === null) {
                                $publication = $sourcePublication;
                                $source = null;
                                $stats['publication_from_source']++;
                                $stats['source_cleared']++;
                            } else {
                                $merged = $this->resolvePublicationPair($publication, $sourcePublication ?? $source);
                                if ($merged !== null) {
                                    $publication = $merged;
                                    $source = null;
                                    $stats['source_cleared']++;
                                } else {
                                    $this->addConflict($conflicts, $stats, $cocktail, 'non-web source differs from publication', $publication . ' <> ' . $source);
                                }
                            }
                        }
                    }

                    $changed = $originalPublication !== $publication || $this->clean($cocktail->source) !== $source;
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

    private function normalizePublication(?string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^Difford[’\']s Guide(?:\s+#\d+)?$/iu', $value) === 1) {
            return "Difford's Guide";
        }

        if (preg_match('/^Franz Brandl\s+"?Cocktails"?$/iu', $value) === 1) {
            return 'Franz Brandl "Cocktails"';
        }

        if (preg_match('/^Cocktail Virgin(?:\/Slut)?$/iu', $value) === 1) {
            return 'Cocktail Virgin/Slut';
        }

        return $value;
    }

    private function resolvePublicationCandidates(array $values): ?string
    {
        $resolved = null;
        foreach ($values as $value) {
            if ($resolved === null) {
                $resolved = $value;
                continue;
            }

            $resolved = $this->resolvePublicationPair($resolved, $value);
            if ($resolved === null) {
                return null;
            }
        }

        return $resolved;
    }

    private function resolvePublicationPair(string $left, string $right): ?string
    {
        $left = $this->normalizePublication($left) ?? $left;
        $right = $this->normalizePublication($right) ?? $right;

        if ($this->sameText($left, $right)) {
            return $left;
        }

        $foodAndWinePattern = '/^Food & Wine:\s*Cocktails(?:\s+(\d{4}))?$/iu';
        if (preg_match($foodAndWinePattern, $left, $leftMatch) === 1 && preg_match($foodAndWinePattern, $right, $rightMatch) === 1) {
            $leftYear = $leftMatch[1] ?? null;
            $rightYear = $rightMatch[1] ?? null;

            if ($leftYear !== null && $rightYear === null) {
                return 'Food & Wine: Cocktails ' . $leftYear;
            }
            if ($rightYear !== null && $leftYear === null) {
                return 'Food & Wine: Cocktails ' . $rightYear;
            }
            if ($leftYear !== null && $leftYear === $rightYear) {
                return 'Food & Wine: Cocktails ' . $leftYear;
            }
        }

        return null;
    }

    private function sameText(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }

    private function normalizeWebUrl(string $value): ?string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true)) {
                return $value;
            }
        }

        if (preg_match('/^(?:www\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}(?::\d+)?(?:[\/?#].*)?$/iu', $value) === 1) {
            $url = 'https://' . $value;
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                return $url;
            }
        }

        return null;
    }
}
