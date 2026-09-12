<?php

declare(strict_types=1);

namespace Kami\Cocktail\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Kami\Cocktail\Models\Cocktail;
use Kami\Cocktail\Models\CocktailTap;

class CocktailTapController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        [$cocktail, $membershipId] = $this->resolveCocktailAndMembership($request, $id);

        $taps = CocktailTap::query()
            ->where('cocktail_id', $cocktail->id)
            ->where('bar_membership_id', $membershipId)
            ->orderByDesc('tapped_on')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $taps->map(fn (CocktailTap $tap) => $this->serializeTap($tap))->values(),
            'meta' => [
                'total' => $taps->count(),
                'last_tapped_on' => $taps->first()?->tapped_on?->format('Y-m-d'),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        $barId = (int) $request->header('Bar-Assistant-Bar-Id', 0);
        if ($barId <= 0) {
            abort(400, 'Bar-Assistant-Bar-Id header is required.');
        }

        $membership = $request->user()->getBarMembership($barId);
        if ($membership === null) {
            abort(403);
        }

        return response()->json([
            'data' => [
                'personal' => [
                    'most_tapped' => $this->tapStatsQuery($barId, (int) $membership->id, 'most'),
                    'last_tapped' => $this->tapStatsQuery($barId, (int) $membership->id, 'last'),
                ],
                'bar' => [
                    'most_tapped' => $this->tapStatsQuery($barId, null, 'most'),
                    'last_tapped' => $this->tapStatsQuery($barId, null, 'last'),
                ],
            ],
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        [$cocktail, $membershipId] = $this->resolveCocktailAndMembership($request, $id);

        $validated = Validator::make($request->all(), [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validate();

        $tap = CocktailTap::create([
            'cocktail_id' => $cocktail->id,
            'bar_membership_id' => $membershipId,
            'tapped_on' => $validated['date'] ?? now()->toDateString(),
        ]);

        return response()->json(['data' => $this->serializeTap($tap)], 201);
    }

    public function update(Request $request, int $id, int $tapId): JsonResponse
    {
        [$cocktail, $membershipId] = $this->resolveCocktailAndMembership($request, $id);

        $validated = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        $tap = CocktailTap::query()
            ->where('id', $tapId)
            ->where('cocktail_id', $cocktail->id)
            ->where('bar_membership_id', $membershipId)
            ->firstOrFail();

        $tap->tapped_on = $validated['date'];
        $tap->save();

        return response()->json(['data' => $this->serializeTap($tap)]);
    }

    public function destroy(Request $request, int $id, int $tapId): Response
    {
        [$cocktail, $membershipId] = $this->resolveCocktailAndMembership($request, $id);

        $tap = CocktailTap::query()
            ->where('id', $tapId)
            ->where('cocktail_id', $cocktail->id)
            ->where('bar_membership_id', $membershipId)
            ->firstOrFail();

        $tap->delete();

        return new Response(null, 204);
    }

    /** @return array<int, array{id: int, name: string, slug: string, tap_count: int, last_tapped_on: string, images: array<int, array{id: int}>}> */
    private function tapStatsQuery(int $barId, ?int $membershipId, string $order): array
    {
        $query = DB::table('cocktail_taps as t')
            ->join('cocktails as c', 'c.id', '=', 't.cocktail_id')
            ->join('bar_memberships as bm', 'bm.id', '=', 't.bar_membership_id')
            ->where('c.bar_id', $barId)
            ->where('bm.bar_id', $barId)
            ->when($membershipId !== null, fn ($q) => $q->where('t.bar_membership_id', $membershipId))
            ->groupBy('c.id', 'c.name', 'c.slug')
            ->select([
                'c.id',
                'c.name',
                'c.slug',
                DB::raw('COUNT(t.id) as tap_count'),
                DB::raw('MAX(t.tapped_on) as last_tapped_on'),
            ]);

        if ($order === 'most') {
            $query->orderByDesc('tap_count')->orderByDesc('last_tapped_on')->orderBy('c.name');
        } else {
            $query->orderByDesc('last_tapped_on')->orderByDesc('tap_count')->orderBy('c.name');
        }

        $rows = $query->limit(8)->get();
        $cocktails = Cocktail::query()
            ->with('images')
            ->whereIn('id', $rows->pluck('id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function ($row) use ($cocktails) {
                $cocktail = $cocktails->get((int) $row->id);

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'tap_count' => (int) $row->tap_count,
                    'last_tapped_on' => (string) $row->last_tapped_on,
                    'images' => $cocktail?->images
                        ->take(1)
                        ->map(fn ($image) => ['id' => (int) $image->id])
                        ->values()
                        ->all() ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array{0: Cocktail, 1: int} */
    private function resolveCocktailAndMembership(Request $request, int $id): array
    {
        $cocktail = Cocktail::findOrFail($id);

        if ($request->user()->cannot('show', $cocktail)) {
            abort(403);
        }

        $membership = $request->user()->getBarMembership((int) $cocktail->bar_id);
        if ($membership === null) {
            abort(403);
        }

        return [$cocktail, (int) $membership->id];
    }

    /** @return array{id: int, date: string} */
    private function serializeTap(CocktailTap $tap): array
    {
        return [
            'id' => (int) $tap->id,
            'date' => $tap->tapped_on->format('Y-m-d'),
        ];
    }
}
