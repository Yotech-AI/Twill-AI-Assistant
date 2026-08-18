<?php

namespace TwillAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Install-wide Twill AI settings (a single row). Holds the active provider, its
 * API key (encrypted at rest), the default chat model, an optional extra system
 * prompt, and the cached list of the provider's models.
 *
 * @property string $provider
 * @property string|null $api_key
 * @property string|null $key_last_four
 * @property Carbon|null $verified_at
 * @property string|null $default_model
 * @property string|null $system_prompt
 * @property array<int, array{id: string, label: string}>|null $available_models
 * @property Carbon|null $models_fetched_at
 */
class TwillAiSetting extends Model
{
    protected $table = 'twill_ai_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'available_models' => 'array',
            'verified_at' => 'datetime',
            'models_fetched_at' => 'datetime',
        ];
    }

    /**
     * The single settings row, created on first access.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }

    /**
     * A key has been entered AND validated AND we have a model list to use.
     */
    public function isConfigured(): bool
    {
        return $this->verified_at !== null && filled($this->api_key) && ! empty($this->available_models);
    }

    public function maskedKey(): ?string
    {
        return $this->key_last_four ? '••••••••'.$this->key_last_four : null;
    }

    /**
     * The decrypted key, but only for the provider it belongs to.
     */
    public function keyFor(string $provider): ?string
    {
        return $provider === $this->provider ? $this->api_key : null;
    }
}
