<?php

namespace App\Services\Ai;

use App\Enums\AiJobStatus;
use App\Models\AiJob;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aggregates measured AI usage and estimated cost for the administrator. Never touches recipe content.
 */
class AiUsageReport
{
    public function __construct(private AiSettings $settings) {}

    /**
     * @return array{jobs: int, succeeded: int, failed: int, active: int, input_tokens: int, output_tokens: int, reasoning_tokens: int, cost_micro: int, unpriced: int}
     */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->between($from, $to)
            ->selectRaw('count(*) as jobs')
            ->selectRaw("sum(case when status = 'succeeded' then 1 else 0 end) as succeeded")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status in ('queued', 'running') then 1 else 0 end) as active")
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(reasoning_tokens), 0) as reasoning_tokens')
            ->selectRaw('coalesce(sum(estimated_cost_micro_usd), 0) as cost_micro')
            ->selectRaw("sum(case when status = 'succeeded' and estimated_cost_micro_usd is null then 1 else 0 end) as unpriced")
            ->toBase()
            ->first();

        return [
            'jobs' => (int) ($row->jobs ?? 0),
            'succeeded' => (int) ($row->succeeded ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'active' => (int) ($row->active ?? 0),
            'input_tokens' => (int) ($row->input_tokens ?? 0),
            'output_tokens' => (int) ($row->output_tokens ?? 0),
            'reasoning_tokens' => (int) ($row->reasoning_tokens ?? 0),
            'cost_micro' => (int) ($row->cost_micro ?? 0),
            'unpriced' => (int) ($row->unpriced ?? 0),
        ];
    }

    /**
     * Per kind + model + profile: how many jobs, how much it cost, average cost of a delivered result.
     *
     * @return Collection<int, object{kind: string, model: string|null, provider: string|null, jobs: int, succeeded: int, failed: int, input_tokens: int, output_tokens: int, cost_micro: int, avg_cost_micro: int|null}>
     */
    public function byModel(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->between($from, $to)
            ->selectRaw('kind, provider, model')
            ->selectRaw('count(*) as jobs')
            ->selectRaw("sum(case when status = 'succeeded' then 1 else 0 end) as succeeded")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('coalesce(sum(estimated_cost_micro_usd), 0) as cost_micro')
            ->groupBy('kind', 'provider', 'model')
            ->orderByDesc('cost_micro')
            ->toBase()
            ->get()
            ->map(function ($row) {
                $succeeded = (int) $row->succeeded;

                return (object) [
                    'kind' => (string) $row->kind,
                    'provider' => $row->provider,
                    'model' => $row->model,
                    'jobs' => (int) $row->jobs,
                    'succeeded' => $succeeded,
                    'failed' => (int) $row->failed,
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    'cost_micro' => (int) $row->cost_micro,
                    'avg_cost_micro' => $succeeded > 0 ? intdiv((int) $row->cost_micro, $succeeded) : null,
                ];
            });
    }

    /**
     * Households ordered by spend (id + name only; no recipe content).
     *
     * @return Collection<int, object{household_id: int, name: string, jobs: int, text_jobs: int, image_jobs: int, cost_micro: int}>
     */
    public function byHousehold(CarbonInterface $from, CarbonInterface $to, int $limit = 20): Collection
    {
        return $this->between($from, $to)
            ->join('households', 'households.id', '=', 'ai_jobs.household_id')
            ->selectRaw('ai_jobs.household_id, households.name')
            ->selectRaw('count(*) as jobs')
            ->selectRaw("sum(case when ai_jobs.kind = 'text' then 1 else 0 end) as text_jobs")
            ->selectRaw("sum(case when ai_jobs.kind = 'image' then 1 else 0 end) as image_jobs")
            ->selectRaw('coalesce(sum(ai_jobs.estimated_cost_micro_usd), 0) as cost_micro')
            ->groupBy('ai_jobs.household_id', 'households.name')
            ->orderByDesc('cost_micro')
            ->limit($limit)
            ->toBase()
            ->get()
            ->map(fn ($row) => (object) [
                'household_id' => (int) $row->household_id,
                'name' => (string) $row->name,
                'jobs' => (int) $row->jobs,
                'text_jobs' => (int) $row->text_jobs,
                'image_jobs' => (int) $row->image_jobs,
                'cost_micro' => (int) $row->cost_micro,
            ]);
    }

    /**
     * One row per local calendar day (application timezone), zero-filled, oldest first.
     *
     * @return list<array{date: string, jobs: int, text_jobs: int, image_jobs: int, failed: int, cost_micro: int}>
     */
    public function daily(CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $this->timezone();
        $days = [];
        $cursor = CarbonImmutable::instance($from)->setTimezone($tz)->startOfDay();
        $end = CarbonImmutable::instance($to)->setTimezone($tz)->startOfDay();
        while ($cursor->lessThanOrEqualTo($end)) {
            $days[$cursor->toDateString()] = ['date' => $cursor->toDateString(), 'jobs' => 0, 'text_jobs' => 0, 'image_jobs' => 0, 'failed' => 0, 'cost_micro' => 0];
            $cursor = $cursor->addDay();
        }

        $this->between($from, $to)
            ->select(['created_at', 'kind', 'status', 'estimated_cost_micro_usd'])
            ->orderBy('created_at')
            ->lazy()
            ->each(function (AiJob $job) use (&$days, $tz) {
                $day = $job->created_at?->setTimezone($tz)->toDateString();
                if ($day === null || ! isset($days[$day])) {
                    return;
                }
                $days[$day]['jobs']++;
                $days[$day][$job->kind->value === 'image' ? 'image_jobs' : 'text_jobs']++;
                if ($job->status === AiJobStatus::Failed) {
                    $days[$day]['failed']++;
                }
                $days[$day]['cost_micro'] += (int) ($job->estimated_cost_micro_usd ?? 0);
            });

        return array_values($days);
    }

    /**
     * Month-to-date spend against the soft budget (application timezone).
     *
     * @return array{spent_micro: int, budget_micro: int|null, ratio: float|null, exceeded: bool, month: string}
     */
    public function budget(): array
    {
        $start = CarbonImmutable::now($this->timezone())->startOfMonth();
        $spent = (int) AiJob::query()->where('created_at', '>=', $start->utc())->sum('estimated_cost_micro_usd');
        $budget = $this->settings->monthlyBudgetMicroUsd();

        return [
            'spent_micro' => $spent,
            'budget_micro' => $budget,
            'ratio' => $budget !== null && $budget > 0 ? round($spent / $budget, 4) : null,
            'exceeded' => $budget !== null && $spent > $budget,
            'month' => $start->format('m/Y'),
        ];
    }

    /**
     * Latest jobs for the admin table, without prompt/output (the admin does not see recipe content by default).
     *
     * @return Collection<int, AiJob>
     */
    public function recentJobs(CarbonInterface $from, CarbonInterface $to, ?string $kind = null, ?string $status = null, int $limit = 50): Collection
    {
        return $this->between($from, $to)
            ->select([
                'id', 'household_id', 'recipe_id', 'kind', 'status', 'provider', 'model', 'profile', 'error',
                'input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens', 'image_output_tokens',
                'estimated_cost_micro_usd', 'cost_rate_id', 'duration_ms', 'created_by', 'created_at', 'finished_at',
            ])
            ->with(['household:id,name'])
            ->when($kind, fn (Builder $q) => $q->where('kind', $kind))
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function timezone(): string
    {
        return (string) config('recipes.default_timezone', 'UTC');
    }

    /** @return Builder<AiJob> */
    private function between(CarbonInterface $from, CarbonInterface $to): Builder
    {
        return AiJob::query()->whereBetween('ai_jobs.created_at', [CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()]);
    }
}
