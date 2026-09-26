<?php

namespace App\Livewire;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Enums\UsageKind;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiConflictException;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUnavailableException;
use App\Services\Usage\UsageBalance;
use App\Support\CurrentHousehold;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * @property-read Recipe $recipe
 * @property-read AiJob|null $job
 * @property-read string|null $unavailable
 * @property-read UsageBalance|null $balance
 * @property-read bool $isStale
 * @property-read list<int> $photoSteps
 */
class AiTextAssistant extends Component
{
    public int $recipeId;

    public string $scope = 'description';

    public ?int $jobId = null;

    /** @var list<string> */
    public array $fields = ['title', 'description', 'ingredients', 'steps'];

    public bool $confirmPhotos = false;

    public string $error = '';

    public string $notice = '';

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;
        $this->jobId = AiJob::query()->where('recipe_id', $recipeId)->where('kind', AiJobKind::Text)->whereNull('applied_at')->latest()->value('id');
    }

    #[Computed]
    public function recipe(): Recipe
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->with(['ingredients', 'steps'])->findOrFail($this->recipeId);
        $this->authorize('update', $recipe);

        return $recipe;
    }

    #[Computed]
    public function job(): ?AiJob
    {
        return $this->jobId ? AiJob::query()->where('recipe_id', $this->recipeId)->find($this->jobId) : null;
    }

    #[Computed]
    public function unavailable(): ?string
    {
        return app(AiAvailability::class)->reasonUnavailable($this->recipe->household, UsageKind::Text);
    }

    /** Uses left for text operations; null when the ledger is not enforced. */
    #[Computed]
    public function balance(): ?UsageBalance
    {
        return app(AiAvailability::class)->balance($this->recipe->household, UsageKind::Text);
    }

    #[Computed]
    public function isStale(): bool
    {
        $job = $this->job;

        return $job !== null && $job->input_revision_id !== $this->recipe->active_revision_id;
    }

    /** @return list<int> */
    #[Computed]
    public function photoSteps(): array
    {
        $job = $this->job;

        return $job && $job->status === AiJobStatus::Succeeded ? app(AiTextService::class)->stepsNeedingPhotoConfirmation($job) : [];
    }

    public function request(AiTextService $service, bool $fresh = false): void
    {
        $this->error = '';
        $this->notice = '';

        try {
            $job = $service->request($this->recipe, auth()->user(), $this->scope, $fresh);
        } catch (AiUnavailableException|InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->jobId = $job->id;
        unset($this->job, $this->photoSteps, $this->balance, $this->unavailable);
    }

    public function refreshStatus(): void
    {
        unset($this->job, $this->photoSteps, $this->balance, $this->unavailable);
    }

    public function apply(AiTextService $service): void
    {
        $job = $this->job;
        if ($job === null) {
            return;
        }

        try {
            $service->apply($job, $this->fields, auth()->user(), $this->confirmPhotos);
        } catch (AiConflictException $e) {
            $this->error = $e->getMessage();

            return;
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->error = '';
        $this->notice = 'Návrh bol použitý ako nová revízia. Pôvodný text zostáva v histórii revízií.';
        unset($this->job, $this->recipe);
        $this->dispatch('ai-applied');
    }

    public function dismiss(): void
    {
        $this->jobId = null;
        $this->error = '';
        unset($this->job);
    }

    public function render(): View
    {
        return view('livewire.ai-text-assistant');
    }
}
