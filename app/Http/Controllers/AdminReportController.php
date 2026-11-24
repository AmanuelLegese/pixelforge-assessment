<?php

namespace App\Http\Controllers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AdminReportController extends Controller
{
    public function __invoke(PixelforgeClient $client): Response
    {
        abort_unless(in_array(request()->user()->email, config('pixelforge.admin_emails'), true), 403);

        // Pixelforge bills on the UTC day a generation completed, so we group the same way.
        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', 'succeeded')
            ->groupByRaw("(generations.completed_at AT TIME ZONE 'UTC')::date, users.id, users.name, users.email")
            ->orderByRaw("(generations.completed_at AT TIME ZONE 'UTC')::date desc")
            ->orderBy('users.name')
            ->selectRaw("(generations.completed_at AT TIME ZONE 'UTC')::date as day, users.id as user_id, users.name, users.email, count(*) as generations, sum(generations.charged)::bigint as charged, sum(generations.provider_cost)::bigint as provider_cost")
            ->get();

        $days = $rows->groupBy('day')->map(fn ($dayRows, $day) => [
            'day' => $day,
            'generations' => (int) $dayRows->sum('generations'),
            'charged' => $this->money((int) $dayRows->sum('charged')),
            'provider_cost' => $this->money((int) $dayRows->sum('provider_cost')),
            'profit' => $this->money((int) ($dayRows->sum('charged') - $dayRows->sum('provider_cost'))),
            'provider_reported' => $this->providerReported($client, $day),
            'users' => $dayRows->map(fn ($r) => [
                'name' => $r->name,
                'email' => $r->email,
                'generations' => (int) $r->generations,
                'charged' => $this->money((int) $r->charged),
                'provider_cost' => $this->money((int) $r->provider_cost),
                'profit' => $this->money((int) ($r->charged - $r->provider_cost)),
            ])->values(),
        ])->values();

        return Inertia::render('admin/Report', [
            'days' => $days,
            'totals' => [
                'charged' => $this->money((int) $rows->sum('charged')),
                'provider_cost' => $this->money((int) $rows->sum('provider_cost')),
                'profit' => $this->money((int) ($rows->sum('charged') - $rows->sum('provider_cost'))),
            ],
        ]);
    }

    /** What GET /v1/usage says this key was billed that day, to reconcile against our records. */
    private function providerReported(PixelforgeClient $client, string $day): ?array
    {
        try {
            $usage = $client->usage($day, $day);
            $nano = Money::fromUsd($usage['total']['cost_usd'] ?? $usage['data'][0]['cost_usd']);

            return $this->money($nano);
        } catch (Throwable) {
            return null;
        }
    }

    private function money(int $nano): array
    {
        return ['nano' => $nano, 'display' => Money::display($nano)];
    }
}
