<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

/**
 * Reasoning effort for OpenAI reasoning models (gpt-5/gpt-6 family). "default" or unknown values send nothing.
 */
trait HasReasoningEffort
{
    /** low|medium|high, or null to let the provider decide. */
    protected ?string $reasoningEffort = null;

    public function withReasoningEffort(?string $effort): static
    {
        $this->reasoningEffort = in_array($effort, ['low', 'medium', 'high'], true) ? $effort : null;

        return $this;
    }

    public function reasoningEffort(): ?string
    {
        return $this->reasoningEffort;
    }

    /**
     * Provider-specific request options. Only the OpenAI Responses API understands `reasoning.effort`.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        if ($this->reasoningEffort === null || ! in_array($driver, ['openai', 'azure'], true)) {
            return [];
        }

        return ['reasoning' => ['effort' => $this->reasoningEffort]];
    }
}
