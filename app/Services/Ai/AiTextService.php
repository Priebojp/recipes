<?php

namespace App\Services\Ai;

use App\Ai\Agents\RecipeTextAgent;
use App\Enums\AiJobKind;
use App\Enums\AiJobStatus;
use App\Jobs\RunRecipeTextJob;
use App\Models\AiJob;
use App\Models\Recipe;
use App\Models\RecipeRevision;
use App\Models\RecipeStep;
use App\Models\User;
use App\Services\RecipeService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laravel\Ai\Responses\StructuredAgentResponse;

class AiTextService
{
    public const SCOPES = ['description', 'steps', 'full'];

    public function __construct(
        private RecipeService $recipes,
        private AiAvailability $availability,
    ) {}

    /**
     * Create (or reuse) a text job for the recipe's current revision. Same recipe+revision+scope => same request_key.
     */
    public function request(Recipe $recipe, ?User $by, string $scope, bool $fresh = false): AiJob
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Neznámy rozsah úpravy.');
        }

        $household = $recipe->household;
        if ($reason = $this->availability->reasonUnavailable($household, AiJobKind::Text)) {
            throw new AiUnavailableException($reason);
        }

        $recipe->load(['ingredients', 'steps', 'mealTypes']);
        $revision = $recipe->active_revision_id
            ? RecipeRevision::find($recipe->active_revision_id)
            : $this->recipes->snapshotRevision($recipe, $by, 'manual');
        $revision ??= $this->recipes->snapshotRevision($recipe, $by, 'manual');

        $version = (string) config('recipes.ai.text_prompt_version');
        $key = hash('sha256', implode('|', ['text', $recipe->id, $revision->id, $scope, $version, $fresh ? microtime(true) : '']));

        $existing = AiJob::query()->where('request_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }

        $job = AiJob::create([
            'household_id' => $recipe->household_id,
            'recipe_id' => $recipe->id,
            'kind' => AiJobKind::Text,
            'input_revision_id' => $revision->id,
            'status' => AiJobStatus::Queued,
            'request_key' => $key,
            'provider' => $this->availability->textProvider(),
            'model' => config('recipes.ai.text_model'),
            'prompt_version' => $version,
            'input' => ['scope' => $scope, 'recipe' => $this->payload($revision->snapshot)],
            'created_by' => $by?->id,
        ]);

        RunRecipeTextJob::dispatch($job->id);

        return $job->fresh();
    }

    /**
     * Only recipe content goes to the provider – no names, history or profiles.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function payload(array $snapshot): array
    {
        return [
            'title' => $snapshot['title'] ?? '',
            'description' => $snapshot['description'] ?? '',
            'ingredients' => array_map(fn ($l) => [
                'id' => $l['id'] ?? null,
                'name' => $l['name'] ?? '',
                'amount' => $l['numeric_amount'] ?? $l['text_amount'] ?? null,
                'unit' => $l['unit'] ?? null,
                'note' => $l['note'] ?? null,
            ], $snapshot['ingredients'] ?? []),
            'steps' => array_map(fn ($s) => ['id' => $s['id'] ?? null, 'text' => $s['text'] ?? ''], $snapshot['steps'] ?? []),
            'raw_text' => $snapshot['raw_text'] ?? '',
        ];
    }

    /**
     * Executed by the queued job: calls the provider and validates the answer.
     */
    public function run(AiJob $job): void
    {
        if ($job->status !== AiJobStatus::Queued) {
            return;
        }
        $job->update(['status' => AiJobStatus::Running, 'started_at' => now()]);

        try {
            $prompt = (string) json_encode([
                'scope' => $job->input['scope'],
                'recipe' => $job->input['recipe'],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $job->update(['prompt' => $prompt]);

            $response = RecipeTextAgent::make()->prompt(
                $prompt,
                provider: $job->provider,
                model: $job->model,
                timeout: (int) config('recipes.ai.timeout_seconds'),
            );

            $structured = $response instanceof StructuredAgentResponse
                ? $response->toArray()
                : (json_decode($response->text, true) ?: []);

            $output = $this->validateOutput(is_array($structured) ? $structured : [], $job->input['recipe']);

            $job->update(['status' => AiJobStatus::Succeeded, 'output' => $output, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
            $job->update(['status' => AiJobStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
        }
    }

    /**
     * Server-side validation of schema and limits.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validateOutput(array $output, array $input): array
    {
        $string = fn ($v, int $max) => mb_substr(trim((string) ($v ?? '')), 0, $max);
        $knownIngredientIds = array_filter(array_map(fn ($l) => $l['id'] ?? null, $input['ingredients'] ?? []));
        $knownStepIds = array_filter(array_map(fn ($s) => $s['id'] ?? null, $input['steps'] ?? []));

        $ingredients = [];
        foreach (array_slice((array) ($output['ingredients'] ?? []), 0, 100) as $line) {
            $name = $string($line['name'] ?? '', 200);
            if ($name === '') {
                continue;
            }
            $sourceId = isset($line['source_id']) && in_array((int) $line['source_id'], $knownIngredientIds, true) ? (int) $line['source_id'] : null;
            $ingredients[] = ['source_id' => $sourceId, 'name' => $name, 'amount' => $string($line['amount'] ?? '', 100) ?: null, 'unit' => $string($line['unit'] ?? '', 50) ?: null, 'note' => $string($line['note'] ?? '', 200) ?: null];
        }

        $steps = [];
        foreach (array_slice((array) ($output['steps'] ?? []), 0, 60) as $step) {
            $text = $string($step['text'] ?? '', 4000);
            if ($text === '') {
                continue;
            }
            $sourceId = isset($step['source_id']) && in_array((int) $step['source_id'], $knownStepIds, true) ? (int) $step['source_id'] : null;
            $steps[] = ['source_id' => $sourceId, 'text' => $text];
        }

        return [
            'suggested_title' => $string($output['suggested_title'] ?? '', 200),
            'suggested_description' => $string($output['suggested_description'] ?? '', 2000),
            'ingredients' => $ingredients,
            'steps' => $steps,
            'questions' => array_values(array_filter(array_map(fn ($q) => $string($q, 500), array_slice((array) ($output['questions'] ?? []), 0, 20)))),
            'change_summary' => array_values(array_filter(array_map(fn ($q) => $string($q, 500), array_slice((array) ($output['change_summary'] ?? []), 0, 30)))),
        ];
    }

    /**
     * Steps of the input revision that carry photos and are not referenced exactly once by the suggestion.
     *
     * @return list<int>
     */
    public function stepsNeedingPhotoConfirmation(AiJob $job): array
    {
        $recipe = $job->recipe()->with('steps.media')->firstOrFail();
        $references = array_count_values(array_filter(array_map(fn ($s) => $s['source_id'], $job->output['steps'] ?? [])));

        return array_values(array_map('intval', $recipe->steps
            ->filter(fn (RecipeStep $s) => $s->getMedia(RecipeStep::IMAGES_COLLECTION)->isNotEmpty() && ($references[$s->id] ?? 0) !== 1)
            ->pluck('id')
            ->all()));
    }

    /**
     * Apply chosen fields of the suggestion as a new AI revision. Refuses when the recipe changed meanwhile.
     *
     * @param  list<string>  $fields  any of title, description, ingredients, steps
     *
     * @throws AiConflictException
     */
    public function apply(AiJob $job, array $fields, ?User $by, bool $confirmPhotoAssignment = false): Recipe
    {
        if ($job->status !== AiJobStatus::Succeeded || $job->output === null) {
            throw new InvalidArgumentException('Návrh ešte nie je hotový.');
        }

        return DB::transaction(function () use ($job, $fields, $by, $confirmPhotoAssignment) {
            $recipe = Recipe::query()->lockForUpdate()->with(['steps.media', 'ingredients'])->findOrFail($job->recipe_id);

            if ($recipe->active_revision_id !== $job->input_revision_id) {
                throw new AiConflictException('Recept bol medzitým upravený. Návrh vychádza zo staršej verzie – porovnaj ho a spusti úpravu znova.');
            }

            $output = $job->output;
            $data = [];

            if (in_array('title', $fields, true) && $output['suggested_title'] !== '') {
                $data['title'] = $output['suggested_title'];
            }
            if (in_array('description', $fields, true)) {
                $data['description'] = $output['suggested_description'];
            }
            if (in_array('ingredients', $fields, true)) {
                $data['ingredients'] = array_map(fn ($l) => ['id' => $l['source_id'], 'name' => $l['name'], 'amount' => $l['amount'], 'unit' => $l['unit'], 'note' => $l['note']], $output['ingredients']);
            }
            if (in_array('steps', $fields, true)) {
                $needsConfirmation = $this->stepsNeedingPhotoConfirmation($job);
                if ($needsConfirmation !== [] && ! $confirmPhotoAssignment) {
                    throw new InvalidArgumentException('Kroky s fotografiami boli zlúčené alebo rozdelené. Potvrď priradenie fotografií pred použitím postupu.');
                }

                $seen = [];
                $steps = [];
                foreach ($output['steps'] as $step) {
                    $id = $step['source_id'];
                    if ($id !== null && isset($seen[$id])) {
                        $id = null; // a split step: only the first part keeps the original id (and its photos)
                    }
                    if ($id !== null) {
                        $seen[$id] = true;
                    }
                    $steps[] = ['id' => $id, 'text' => $step['text']];
                }

                // Photos of steps the suggestion dropped are moved to the last step so nothing is lost.
                $data['steps'] = $steps;
                $data['__orphan_photo_steps'] = $recipe->steps->filter(fn (RecipeStep $s) => ! isset($seen[$s->id]) && $s->getMedia(RecipeStep::IMAGES_COLLECTION)->isNotEmpty())->pluck('id')->all();
            }

            $orphans = $data['__orphan_photo_steps'] ?? [];
            unset($data['__orphan_photo_steps']);

            // Photos of steps the suggestion dropped are parked on the recipe and re-attached after the update,
            // so the step deletion inside the update cannot delete them.
            $parked = [];
            foreach ($recipe->steps->whereIn('id', $orphans) as $orphan) {
                foreach ($orphan->getMedia(RecipeStep::IMAGES_COLLECTION) as $media) {
                    $parked[] = $media->move($recipe, 'parked_step_images');
                }
            }

            $recipe = $this->recipes->update($recipe, $by, $data, null, 'ai');

            if ($parked !== []) {
                $target = $recipe->steps()->orderByDesc('position')->first();
                foreach ($parked as $media) {
                    if ($target !== null) {
                        $media->move($target, RecipeStep::IMAGES_COLLECTION);
                    } else {
                        $media->delete();
                    }
                }
            }

            $job->update(['applied_at' => now()]);

            return $recipe;
        });
    }
}
