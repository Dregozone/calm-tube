<?php

use App\Models\Video;

it('sends the root url to the feed', function (): void {
    $this->actingAs(calmUser())
        ->get('/')
        ->assertRedirect(route('feed'));
});

it('works with no YouTube API key configured', function (): void {
    config()->set('calm-tube.api_key');
    Video::factory()->for(calmChannel())->create();

    $this->actingAs(calmUser())
        ->get(route('feed'))
        ->assertOk();
});

it('defines the settings the app tunes itself with', function (string $key): void {
    expect(config()->has($key))->toBeTrue();
})->with([
    'calm-tube.refresh.timeout',
    'calm-tube.refresh.retries',
    'calm-tube.shorts.probe',
    'calm-tube.shorts.probe_max_seconds',
    'calm-tube.shorts.fallback_max_seconds',
    'calm-tube.images.minimum_bytes',
    'calm-tube.images.disk',
    'calm-tube.feed.per_page',
]);

it('sets the Shorts thresholds to keep genuinely short videos', function (): void {
    expect(config('calm-tube.shorts.probe_max_seconds'))->toBe(180)
        ->and(config('calm-tube.shorts.fallback_max_seconds'))->toBe(60);
});

it('writes refresh activity to its own log channel', function (): void {
    expect(config('logging.channels.calm'))->not->toBeNull();
});

/**
 * Reads the config file afresh with the given environment, as a deploy would.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function calmConfigWith(array $environment): array
{
    $keys = ['CALM_TUBE_AI_PROVIDER', 'CALM_TUBE_AI_MODEL', 'OPENROUTER_API_KEY'];
    $saved = array_map(fn (string $key): array => [$_ENV[$key] ?? null, $_SERVER[$key] ?? null], array_combine($keys, $keys));

    foreach ($keys as $key) {
        unset($_ENV[$key], $_SERVER[$key]);

        if (isset($environment[$key])) {
            $_ENV[$key] = $_SERVER[$key] = $environment[$key];
        }
    }

    try {
        return require config_path('calm-tube.php');
    } finally {
        foreach ($saved as $key => [$env, $server]) {
            unset($_ENV[$key], $_SERVER[$key]);

            if ($env !== null) {
                $_ENV[$key] = $env;
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }
}

it('asks the local model when there is no OpenRouter key', function (): void {
    expect(calmConfigWith([])['ai'])->toMatchArray([
        'provider' => 'ollama',
        'model' => 'qwen3.5:4b',
    ]);
});

it('switches to a small hosted model when an OpenRouter key is set', function (): void {
    expect(calmConfigWith(['OPENROUTER_API_KEY' => 'sk-or-test'])['ai'])->toMatchArray([
        'provider' => 'openrouter',
        'model' => 'google/gemini-2.5-flash-lite',
    ]);
});

it('lets an explicit provider and model win over the OpenRouter key', function (): void {
    expect(calmConfigWith([
        'OPENROUTER_API_KEY' => 'sk-or-test',
        'CALM_TUBE_AI_PROVIDER' => 'ollama',
        'CALM_TUBE_AI_MODEL' => 'llama3.2',
    ])['ai'])->toMatchArray([
        'provider' => 'ollama',
        'model' => 'llama3.2',
    ]);
});
