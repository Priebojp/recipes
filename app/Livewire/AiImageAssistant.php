<?php

namespace App\Livewire;

use App\Enums\AiJobKind;
use App\Enums\ServingMode;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiImageService;
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
 * @property-read array{needs_description: bool, serving_mode: string, summary: string, prompt: string|null, auto_suggested: bool} $preview
 * @property-read string|null $unavailable
 * @property-read UsageBalance|null $balance
 */
class AiImageAssistant extends Component
{
    public int $recipeId;

    public bool $open = false;

    public string $description = '';

    public string $mode = 'auto';

    public ?int $jobId = null;

    public string $error = '';

    public function mount(int $recipeId): void
    {
        $this->recipeId = $recipeId;
        $this->description = (string) $this->recipe->description;
        $this->mode = $this->recipe->serving_mode->value;
        $this->jobId = AiJob::query()->where('recipe_id', $recipeId)->where('kind', AiJobKind::Image)->whereNull('applied_at')->whereNotNull('result_media_id')->orWhere(fn ($q) => $q->where('recipe_id', $recipeId)->where('kind', AiJobKind::Image)->whereIn('status', ['queued', 'running']))->latest()->value('id');
    }

    #[Computed]
    public function recipe(): Recipe
    {
        $recipe = Recipe::query()->where('household_id', app(CurrentHousehold::class)->id())->with(['ingredients', 'steps', 'mealTypes'])->findOrFail($this->recipeId);
        $this->authorize('update', $recipe);

        return $recipe;
    }

    #[Computed]
    public function job(): ?AiJob
    {
        return $this->jobId ? AiJob::query()->where('recipe_id', $this->recipeId)->find($this->jobId) : null;
    }

    /** @return array{needs_description: bool, serving_mode: string, summary: string, prompt: string|null, auto_suggested: bool} */
    #[Computed]
    public function preview(): array
    {
        return app(AiImageService::class)->preview($this->recipe, $this->description, $this->mode);
    }

    #[Computed]
    public function unavailable(): ?string
    {
        return app(AiAvailability::class)->reasonUnavailable($this->recipe->household, AiJobKind::Image);
    }

    /** Uses left for Standard images; null when the ledger is not enforced. */
    #[Computed]
    public function balance(): ?UsageBalance
    {
        return app(AiAvailability::class)->balance($this->recipe->household, AiJobKind::Image);
    }

    public function generate(AiImageService $service, bool $variant = false): void
    {
        $this->error = '';

        try {
            $job = $service->request($this->recipe, auth()->user(), $this->description, $this->mode, $variant);
        } catch (AiUnavailableException|InvalidArgumentException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->jobId = $job->id;
        unset($this->job, $this->balance, $this->unavailable);
    }

    public function refreshStatus(): void
    {
        unset($this->job, $this->balance, $this->unavailable);
    }

    public function approve(AiImageService $service): void
    {
        if ($job = $this->job) {
            try {
                $service->approve($job);
            } catch (InvalidArgumentException $e) {
                $this->error = $e->getMessage();

                return;
            }
            $this->jobId = null;
            $this->open = false;
            unset($this->job, $this->recipe);
            $this->dispatch('ai-applied');
        }
    }

    public function discard(AiImageService $service): void
    {
        if ($job = $this->job) {
            $service->discard($job);
        }
        $this->jobId = null;
        unset($this->job);
    }

    public function render(): View
    {
        return view('livewire.ai-image-assistant', ['modes' => ServingMode::cases()]);
    }
}
