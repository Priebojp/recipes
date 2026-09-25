<?php

namespace App\Services\Ai;

use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Jobs\GenerateRecipeImageJob;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\User;
use App\Services\ImageUploadService;
use App\Services\RecipeService;
use InvalidArgumentException;
use Laravel\Ai\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AiImageService
{
    public function __construct(
        private RecipeService $recipes,
        private AiAvailability $availability,
        private ImagePromptBuilder $prompts,
        private ImageUploadService $uploads,
    ) {}

    /**
     * Preview of what would be generated, for the confirmation step.
     *
     * @return array{needs_description: bool, serving_mode: string, summary: string, prompt: string|null, auto_suggested: bool}
     */
    public function preview(Recipe $recipe, ?string $description = null, ?string $mode = null): array
    {
        $recipe->loadMissing(['ingredients', 'steps', 'mealTypes']);

        return $this->prompts->build(RecipeService::snapshot($recipe), $description, $mode);
    }

    /**
     * Queue a generation. Identical inputs reuse the job (a network retry never pays twice); "another variant" is explicit.
     */
    public function request(Recipe $recipe, ?User $by, string $description, string $mode, bool $variant = false): AiJob
    {
        $household = $recipe->household;
        if ($reason = $this->availability->reasonUnavailable($household, AiJobKind::Image)) {
            throw new AiUnavailableException($reason);
        }

        $preview = $this->preview($recipe, $description, $mode);
        if ($preview['needs_description'] || $preview['prompt'] === null) {
            throw new InvalidArgumentException('Doplň krátky opis jedla, z názvu sa nedá určiť, čo zobraziť.');
        }

        $recipe->loadMissing(['ingredients', 'steps', 'mealTypes']);
        $revision = $recipe->active_revision_id ? RecipeRevision::find($recipe->active_revision_id) : null;
        $revision ??= $this->recipes->snapshotRevision($recipe, $by, 'manual');

        $version = (string) config('recipes.ai.image_prompt_version');
        $key = hash('sha256', implode('|', ['image', $recipe->id, $revision->id, $preview['prompt'], $version, $variant ? microtime(true) : '']));

        $existing = AiJob::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $job = AiJob::create([
            'household_id' => $recipe->household_id,
            'recipe_id' => $recipe->id,
            'kind' => AiJobKind::Image,
            'input_revision_id' => $revision->id,
            'status' => AiJobStatus::Queued,
            'request_key' => $key,
            'provider' => $this->availability->imageProvider(),
            'model' => config('recipes.ai.image_model'),
            'prompt_version' => $version,
            'input' => ['description' => $description, 'serving_mode' => $preview['serving_mode'], 'summary' => $preview['summary']],
            'prompt' => $preview['prompt'],
            'created_by' => $by?->id,
        ]);

        GenerateRecipeImageJob::dispatch($job->id);

        return $job->fresh();
    }

    /**
     * Executed by the queue. Never activates the cover automatically – the user approves first.
     */
    public function run(AiJob $job): void
    {
        if ($job->status !== AiJobStatus::Queued) {
            return;
        }
        $job->update(['status' => AiJobStatus::Running, 'started_at' => now()]);

        $temp = null;

        try {
            $response = Image::of((string) $job->prompt)
                ->size('4:3')
                ->timeout((int) config('recipes.ai.timeout_seconds'))
                ->generate($job->provider, $job->model);

            $image = $response->firstImage();
            $temp = tempnam(sys_get_temp_dir(), 'ai-image');
            file_put_contents($temp, $image->content());

            $recipe = Recipe::findOrFail($job->recipe_id);
            $media = $this->uploads->addCover($recipe, $temp, 'ai', $job->id, activate: false);

            $job->update([
                'status' => AiJobStatus::Succeeded,
                'result_media_id' => $media->id,
                'provider_job_id' => $response->meta->id ?? null,
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $job->update(['status' => AiJobStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        } finally {
            if ($temp && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    public function resultMedia(AiJob $job): ?Media
    {
        return $job->result_media_id ? Media::find($job->result_media_id) : null;
    }

    /**
     * User approval: the AI illustration becomes the main image (the previous one stays restorable).
     */
    public function approve(AiJob $job): void
    {
        $media = $this->resultMedia($job);
        if ($media === null) {
            throw new InvalidArgumentException('Výsledok už nie je dostupný.');
        }

        $this->uploads->activateCover($job->recipe, $media);
        $job->update(['applied_at' => now()]);
    }

    public function discard(AiJob $job): void
    {
        $media = $this->resultMedia($job);
        if ($media !== null && $job->recipe->cover_media_id !== $media->id) {
            $media->delete();
        }
        $job->update(['result_media_id' => null]);
    }
}
