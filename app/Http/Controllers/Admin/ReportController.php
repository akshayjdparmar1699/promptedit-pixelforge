<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GenerationStatus;
use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operator report. Days are UTC days of completed_at, which is how Pixelforge assigns a
 * generation to a day in GET /v1/usage, so the provider cost per day can be compared 1:1.
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        abort_unless($request->user()->is_admin, 403);

        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $end = CarbonImmutable::parse($data['end_date'] ?? now('UTC')->toDateString(), 'UTC');
        $start = CarbonImmutable::parse($data['start_date'] ?? $end->subDays(29)->toDateString(), 'UTC');

        if ($start->diffInDays($end) > 91) {
            $start = $end->subDays(91);
        }

        $rows = Generation::query()
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->whereBetween('generations.completed_at', [$start->startOfDay(), $end->endOfDay()])
            ->groupBy(DB::raw('date(generations.completed_at)'), 'users.id', 'users.name', 'users.email')
            ->orderBy(DB::raw('date(generations.completed_at)'), 'desc')
            ->get([
                DB::raw('date(generations.completed_at) as day'),
                'users.id as user_id',
                'users.name',
                'users.email',
                DB::raw('count(*) as generations'),
                DB::raw('sum(generations.charge_nanos) as charged'),
                DB::raw('sum(generations.provider_cost_nanos) as cost'),
            ])
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'user_id' => (int) $r->user_id,
                'name' => $r->name,
                'email' => $r->email,
                'generations' => (int) $r->generations,
                'charged_nanos' => (int) $r->charged,
                'cost_nanos' => (int) $r->cost,
                'profit_nanos' => (int) $r->charged - (int) $r->cost,
            ]);

        [$usage, $usageError] = $this->usage($client, $start->toDateString(), $end->toDateString());

        $days = collect(CarbonPeriod::create($start, $end))
            ->map(fn ($d) => $d->toDateString())
            ->reverse()
            ->values()
            ->map(function (string $day) use ($rows, $usage) {
                $dayRows = $rows->where('day', $day);
                $cost = $dayRows->sum('cost_nanos');
                $charged = $dayRows->sum('charged_nanos');

                // A day missing from a successful usage response means Pixelforge billed nothing that day.
                $reported = $usage === null ? null : ($usage[$day] ?? ['cost_nanos' => 0, 'generations' => 0]);

                return [
                    'day' => $day,
                    'generations' => $dayRows->sum('generations'),
                    'charged_nanos' => $charged,
                    'cost_nanos' => $cost,
                    'profit_nanos' => $charged - $cost,
                    'pixelforge_cost_nanos' => $reported['cost_nanos'] ?? null,
                    'pixelforge_generations' => $reported['generations'] ?? null,
                    'matches' => $reported === null ? null : $reported['cost_nanos'] === $cost,
                ];
            })
            ->filter(fn ($d) => $d['generations'] > 0 || ($d['pixelforge_generations'] ?? 0) > 0)
            ->values();

        $users = $rows->groupBy('user_id')->map(fn ($g) => [
            'user_id' => $g->first()['user_id'],
            'name' => $g->first()['name'],
            'email' => $g->first()['email'],
            'generations' => $g->sum('generations'),
            'charged_nanos' => $g->sum('charged_nanos'),
            'cost_nanos' => $g->sum('cost_nanos'),
            'profit_nanos' => $g->sum('profit_nanos'),
        ])->sortByDesc('charged_nanos')->values();

        return Inertia::render('admin/Report', [
            'range' => ['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            'days' => $days,
            'users' => $users,
            'rows' => $rows->values(),
            'totals' => [
                'generations' => $rows->sum('generations'),
                'charged_nanos' => $rows->sum('charged_nanos'),
                'cost_nanos' => $rows->sum('cost_nanos'),
                'profit_nanos' => $rows->sum('profit_nanos'),
                'pixelforge_cost_nanos' => $usage === null ? null : array_sum(array_column($usage, 'cost_nanos')),
            ],
            'usageError' => $usageError,
        ]);
    }

    /** @return array{0: array<string, array{cost_nanos: int, generations: int}>|null, 1: string|null} */
    private function usage(PixelforgeClient $client, string $start, string $end): array
    {
        try {
            $body = Cache::remember("pixelforge.usage.$start.$end", now()->addSeconds(60), fn () => $client->usage($start, $end));
        } catch (PixelforgeApiException $e) {
            return [null, 'Could not load GET /v1/usage from Pixelforge ('.$e->errorCode.').'];
        }

        $byDay = [];

        foreach ($body['data'] ?? [] as $day) {
            $byDay[$day['date']] = [
                'cost_nanos' => Money::decimalToNanos($day['cost_usd']),
                'generations' => (int) $day['generations'],
            ];
        }

        return [$byDay, null];
    }
}
