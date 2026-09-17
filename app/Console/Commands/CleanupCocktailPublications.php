<?php

declare(strict_types=1);

namespace Kami\Cocktail\Console\Commands;

use Illuminate\Console\Command;
use Kami\Cocktail\Models\Cocktail;

class CleanupCocktailPublications extends Command
{
    protected $signature = 'bar:cleanup-cocktail-publications {--dry-run : Show planned changes without writing them}';

    protected $description = 'Clean migrated cocktail publication values and move embedded author, year, and URL data to the proper fields';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = [
            'cocktails' => 0,
            'publication_cleared' => 0,
            'publication_cleaned' => 0,
            'author_set' => 0,
            'author_expanded' => 0,
            'year_set' => 0,
            'url_moved_to_source' => 0,
            'conflicts' => 0,
            'manual_review' => 0,
        ];
        $conflicts = [];
        $manualReview = [];

        if ($dryRun) {
            $this->info('DRY RUN - no data will be changed.');
        }

        Cocktail::query()
            ->orderBy('id')
            ->chunkById(250, function ($cocktails) use ($dryRun, &$stats, &$conflicts, &$manualReview): void {
                foreach ($cocktails as $cocktail) {
                    $stats['cocktails']++;

                    $publication = $this->clean($cocktail->publication);
                    if ($publication === null) {
                        continue;
                    }

                    $originalPublication = $publication;
                    $author = $this->clean($cocktail->author);
                    $year = $cocktail->year !== null ? (int) $cocktail->year : null;
                    $source = $this->clean($cocktail->source);

                    if ($this->isDiscardPublication($publication)) {
                        $publication = null;
                        $stats['publication_cleared']++;
                    } elseif ($this->looksLikeUrl($publication)) {
                        $url = $this->normalizeUrl($publication);
                        if ($source === null) {
                            $source = $url;
                            $publication = null;
                            $stats['url_moved_to_source']++;
                            $stats['publication_cleared']++;
                        } elseif ($this->sameText($source, $url)) {
                            $publication = null;
                            $stats['publication_cleared']++;
                        } else {
                            $this->addConflict($conflicts, $stats, $cocktail, 'publication URL differs from existing source', $publication . ' <> ' . $source);
                        }
                    } elseif (preg_match('/^Douglas Ankrah,\s*The Townhouse\s*\|\s*London$/iu', $publication) === 1) {
                        if ($this->applyAuthor($cocktail, 'Douglas Ankrah', $author, $stats, $conflicts)) {
                            $author = 'Douglas Ankrah';
                            $publication = null;
                            $stats['publication_cleared']++;
                        }
                    } elseif (preg_match('/^Jim Meehan\s*\[(\d{4})\]$/iu', $publication, $match) === 1) {
                        $canApply = true;
                        if (!$this->applyAuthor($cocktail, 'Jim Meehan', $author, $stats, $conflicts)) {
                            $canApply = false;
                        } else {
                            $author = 'Jim Meehan';
                        }
                        if (!$this->applyYear($cocktail, (int) $match[1], $year, $stats, $conflicts)) {
                            $canApply = false;
                        } else {
                            $year = (int) $match[1];
                        }
                        if ($canApply) {
                            $publication = null;
                            $stats['publication_cleared']++;
                        }
                    } else {
                        $result = $this->cleanPublicationValue($publication);
                        if ($result !== null) {
                            [$newPublication, $embeddedAuthor, $embeddedYear] = $result;
                            $canApply = true;

                            if ($embeddedAuthor !== null) {
                                if ($this->applyAuthor($cocktail, $embeddedAuthor, $author, $stats, $conflicts)) {
                                    $author = $embeddedAuthor;
                                } else {
                                    $canApply = false;
                                }
                            }

                            if ($embeddedYear !== null) {
                                if ($this->applyYear($cocktail, $embeddedYear, $year, $stats, $conflicts)) {
                                    $year = $embeddedYear;
                                } else {
                                    $canApply = false;
                                }
                            }

                            if ($canApply) {
                                $publication = $newPublication;
                            }
                        }
                    }

                    if ($publication !== null) {
                        $publication = trim($publication);
                    }

                    if ($publication !== $originalPublication && $publication !== null) {
                        $stats['publication_cleaned']++;
                    }

                    if ($publication !== null && $this->isSuspiciousPublication($publication)) {
                        $stats['manual_review']++;
                        $manualReview[] = [$cocktail->id, $cocktail->name, $publication];
                    }

                    $changed = $publication !== $this->clean($cocktail->publication)
                        || $author !== $this->clean($cocktail->author)
                        || $year !== ($cocktail->year !== null ? (int) $cocktail->year : null)
                        || $source !== $this->clean($cocktail->source);

                    if ($changed && !$dryRun) {
                        $cocktail->publication = $publication;
                        $cocktail->author = $author;
                        $cocktail->year = $year;
                        $cocktail->source = $source;
                        $cocktail->save();
                    }
                }
            });

        $this->newLine();
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, $name) => [$name, $count])->values()->all());

        if ($conflicts !== []) {
            $this->newLine();
            $this->warn('Conflicts were left unchanged and require manual review:');
            $this->table(['ID', 'Cocktail', 'Reason', 'Values'], $conflicts);
        }

        if ($manualReview !== []) {
            $this->newLine();
            $this->warn('Suspicious publication values left unchanged for manual review:');
            $this->table(['ID', 'Cocktail', 'Publication'], $manualReview);
        }

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }

    private function cleanPublicationValue(string $value): ?array
    {
        $value = trim($value);

        if (preg_match('/^Beachbum Berry[’\']s Grog Log\s+by\s+Jeff Berry\s*&\s*Annene Kaye(?:,\s*p\.\s*\d+.*)?$/iu', $value) === 1) {
            return ["Beachbum Berry's Grog Log", 'Jeff Berry & Annene Kaye', null];
        }

        if (preg_match('/^(.+?)\s*\((\d{4})\)\s*$/u', $value, $match) === 1) {
            return [trim($match[1]), null, (int) $match[2]];
        }

        if (preg_match('/^(.+?)\s*\(([^()]+)\)\s*$/u', $value, $match) === 1 && $this->looksLikeAuthorList($match[2])) {
            return [trim($match[1]), trim($match[2]), null];
        }

        if (preg_match('/^(.+?)\s+by\s+(.+?)(?:,\s*p\.\s*\d+.*)?$/iu', $value, $match) === 1 && $this->looksLikeAuthorList($match[2])) {
            return [trim($match[1]), trim($match[2]), null];
        }

        if (preg_match('/^(.+?)(?:,\s*)?\s+p\.\s*\d+(?:[-–]\d+)?\s*$/iu', $value, $match) === 1) {
            return [trim($match[1]), null, null];
        }

        return null;
    }

    private function isDiscardPublication(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));

        if (in_array($normalized, [
            'quelle',
            'quelle /',
            'source',
            'source /',
            'unbekannte herkunft',
            'unknown source',
            'unknown',
            '@ndh_creative',
            '@ndh\\_creative',
        ], true)) {
            return true;
        }

        return preg_match('/^@[-_.a-z0-9]+$/iu', trim($value)) === 1;
    }

    private function looksLikeAuthorList(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('/\d/', $value) === 1) {
            return false;
        }

        if (preg_match('/https?:\/\//iu', $value) === 1) {
            return false;
        }

        $parts = preg_split('/\s*(?:&|\band\b)\s*/iu', $value) ?: [];
        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || str_word_count(str_replace(["'", '’', '-'], '', $part)) > 6) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeUrl(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/^https?:\/\//iu', $value) === 1 || preg_match('/^www\./iu', $value) === 1) {
            return true;
        }

        return preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?::\d+)?\/.+/iu', $value) === 1;
    }

    private function normalizeUrl(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^https?:\/\//iu', $value) !== 1) {
            $value = 'https://' . $value;
        }

        return str_replace(' ', '%20', $value);
    }

    private function applyAuthor(Cocktail $cocktail, string $candidate, ?string $current, array &$stats, array &$conflicts): bool
    {
        $candidate = trim($candidate);
        if ($current === null) {
            $stats['author_set']++;
            return true;
        }

        if ($this->sameText($current, $candidate)) {
            return true;
        }

        if ($this->authorListContains($candidate, $current)) {
            $stats['author_expanded']++;
            return true;
        }

        $this->addConflict($conflicts, $stats, $cocktail, 'embedded author differs from existing author', $candidate . ' <> ' . $current);
        return false;
    }

    private function applyYear(Cocktail $cocktail, int $candidate, ?int $current, array &$stats, array &$conflicts): bool
    {
        if ($current === null) {
            $stats['year_set']++;
            return true;
        }

        if ($current === $candidate) {
            return true;
        }

        $this->addConflict($conflicts, $stats, $cocktail, 'embedded year differs from existing year', $candidate . ' <> ' . $current);
        return false;
    }

    private function authorListContains(string $candidate, string $current): bool
    {
        $candidateParts = $this->authorParts($candidate);
        $currentParts = $this->authorParts($current);

        if ($candidateParts === [] || $currentParts === [] || count($candidateParts) <= count($currentParts)) {
            return false;
        }

        foreach ($currentParts as $part) {
            if (!in_array($part, $candidateParts, true)) {
                return false;
            }
        }

        return true;
    }

    private function authorParts(string $value): array
    {
        $parts = preg_split('/\s*(?:&|\band\b)\s*/iu', trim($value)) ?: [];

        return array_values(array_filter(array_map(
            fn (string $part): string => mb_strtolower(trim($part)),
            $parts
        ), fn (string $part): bool => $part !== ''));
    }

    private function isSuspiciousPublication(string $value): bool
    {
        if (preg_match('/\(\d{4}\s+Revised Edition\)\s*$/iu', $value) === 1) {
            return false;
        }

        return preg_match('/https?:\/\//iu', $value) === 1
            || preg_match('/\bp\.\s*\d+/iu', $value) === 1
            || preg_match('/\([^()]+\)\s*$/u', $value) === 1
            || preg_match('/\|/u', $value) === 1
            || preg_match('/^@/u', $value) === 1;
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

    private function addConflict(array &$conflicts, array &$stats, Cocktail $cocktail, string $reason, string $values): void
    {
        $stats['conflicts']++;
        $conflicts[] = [$cocktail->id, $cocktail->name, $reason, $values];
    }
}
