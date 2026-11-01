<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Extracts a reviewable menu draft. OpenRouter is preferred when configured;
 * Mistral is the fallback for transport, JSON, or schema failures.
 * Provider output is untrusted: ambiguous dietary flags and prices remain
 * unresolved for owner review. Measurements belong in evaluation reports,
 * not permanent accuracy or latency guarantees in source comments.
 */
class MenuScanner
{
    private const MISTRAL_ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';

    /** Attempts per OpenAI-compatible provider: the initial try plus one retry. */
    private const MAX_ATTEMPTS = 2;

    /** Delay before the retry attempt, in microseconds (~1.5s). */
    private const RETRY_DELAY_US = 1_500_000;

    private const PROMPT = <<<'TXT'
You are a menu-digitizing assistant for a restaurant POS. Read this menu image and extract every dish.

Return ONLY valid JSON (no markdown fences, no commentary) shaped exactly like:
{"categories":[{"name":"Starters","items":[{"name":"Paneer Tikka","price":250,"is_veg":true,"variations":[{"name":"Half","price":150},{"name":"Full","price":250}]}]}]}

Rules:
- Group items under their printed section/category headings. If a dish has no heading, put it under "Menu".
- price: the item's price as a plain number in rupees (no symbols, commas or "/-").
- If a dish lists multiple sizes/portions (Half/Full, Quarter/Half/Full, Small/Regular/Large), list each under "variations" with its price and set the item "price" to the smallest one. Otherwise omit "variations".
- is_veg: true for vegetarian (green mark), false for non-veg (red/brown mark). If unclear, null so the owner must review it.
- Read every column and section. Do NOT invent items.
- Skip non-dish text: restaurant name, address, phone, GST/tax notes, taglines, decorative text.
TXT;

    /**
     * Scan one or more image data-URIs and merge the results.
     *
     * @param  string[]  $dataUris  e.g. "data:image/jpeg;base64,...."
     * @return array<int, array{name:string, items:array}>
     */
    public function scan(array $dataUris): array
    {
        $merged = [];   // lowercase category name => ['name' => ..., 'items' => [name(lower) => item]]

        foreach ($dataUris as $uri) {
            foreach ($this->scanOne($uri) as $cat) {
                $ck = mb_strtolower(trim($cat['name']));
                $merged[$ck] ??= ['name' => $cat['name'], 'items' => []];
                foreach ($cat['items'] as $item) {
                    $ik = mb_strtolower(trim($item['name']));
                    if ($ik !== '') {
                        $merged[$ck]['items'][$ik] ??= $item; // first occurrence wins
                    }
                }
            }
        }

        return array_values(array_map(
            fn ($c) => ['name' => $c['name'], 'items' => array_values($c['items'])],
            $merged,
        ));
    }

    /**
     * Scan a single image: OpenRouter, falling back to Mistral, using the
     * first configured provider that succeeds.
     *
     * @return array<int, array{name:string, items:array}>
     */
    private function scanOne(string $dataUri): array
    {
        $openRouterKey = config('services.openrouter.key');
        $mistralKey = config('services.mistral.key');

        if (! $openRouterKey && ! $mistralKey) {
            throw new RuntimeException('Menu scanning is not configured — set OPENROUTER_API_KEY or MISTRAL_API_KEY.');
        }

        $lastError = null;

        if ($openRouterKey) {
            try {
                return $this->normalize($this->decodeJson($this->scanViaOpenAiCompatible(
                    $dataUri,
                    config('services.openrouter.base_url'),
                    config('services.openrouter.vision_model'),
                    $openRouterKey,
                    timeout: 30,
                    extraHeaders: ['HTTP-Referer' => config('app.url'), 'X-Title' => 'Billing Menu Import'],
                )));
            } catch (\Throwable $e) {
                // Falling back is routine (rate limits, transient provider
                // hiccups), not an operational error, so this stays at info
                // level to avoid spamming the error log.
                Log::info('OpenRouter menu scan failed, falling back to Mistral: '.$e->getMessage());
                $lastError = $e;
            }
        }

        if ($mistralKey) {
            try {
                return $this->normalize($this->decodeJson($this->scanViaMistral($dataUri)));
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        // Every configured provider was tried and failed — surface the last error.
        throw $lastError;
    }

    /**
     * POST an OpenAI-compatible chat-completions vision request (OpenRouter
     * today) and return the raw model reply text. Retries once after ~1.5s
     * on a connection/timeout exception, HTTP 429, or any HTTP 5xx — the
     * provider occasionally throttles or blips, and a single retry clears
     * most of those without adding much latency.
     */
    private function scanViaOpenAiCompatible(
        string $dataUri,
        string $baseUrl,
        string $model,
        string $key,
        int $timeout,
        array $extraHeaders = [],
    ): string {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $isLastAttempt = $attempt === self::MAX_ATTEMPTS;

            try {
                $res = Http::withToken($key)
                    ->withHeaders($extraHeaders)
                    ->timeout($timeout)
                    ->post(rtrim($baseUrl, '/').'/chat/completions', [
                        'model' => $model,
                        'temperature' => 0,
                        'messages' => [[
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => self::PROMPT],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
                            ],
                        ]],
                    ]);
            } catch (ConnectionException $e) {
                if ($isLastAttempt) {
                    throw new RuntimeException('Vision request failed (connection error).', 0, $e);
                }
                usleep(self::RETRY_DELAY_US);

                continue;
            }

            if ($res->successful()) {
                return (string) data_get($res->json(), 'choices.0.message.content', '');
            }

            $retryable = $res->status() === 429 || $res->status() >= 500;

            if ($retryable && ! $isLastAttempt) {
                usleep(self::RETRY_DELAY_US);

                continue;
            }

            throw new RuntimeException('Vision request failed ('.$res->status().').');
        }

        // Unreachable — the loop above always returns or throws.
        throw new RuntimeException('Vision request failed after retry.');
    }

    /** POST to Mistral's vision endpoint and return the raw model reply text. */
    private function scanViaMistral(string $dataUri): string
    {
        $res = Http::withToken(config('services.mistral.key'))->timeout(30)->post(self::MISTRAL_ENDPOINT, [
            'model' => config('services.mistral.vision_model'),
            'temperature' => 0,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => $dataUri],
                    ['type' => 'text', 'text' => self::PROMPT],
                ],
            ]],
        ]);

        if (! $res->successful()) {
            throw new RuntimeException('Mistral request failed ('.$res->status().'). Check the API key and try again.');
        }

        return (string) data_get($res->json(), 'choices.0.message.content', '');
    }

    /** Pull the JSON object out of the model's reply, tolerating fences / stray text. */
    private function decodeJson(string $text): array
    {
        $text = preg_replace('/```(?:json)?/i', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new RuntimeException('Could not read the menu from that image — try a clearer, straight-on photo.');
        }
        try {
            $decoded = json_decode(substr($text, $start, $end - $start + 1), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Vision response contained invalid JSON.', 0, $e);
        }
        if (! is_array($decoded) || ! isset($decoded['categories']) || ! is_array($decoded['categories']) || ! array_is_list($decoded['categories']) || count($decoded['categories']) > 100) {
            throw new RuntimeException('Vision response contained an invalid menu schema.');
        }

        return $decoded;
    }

    private function normalize(array $data): array
    {
        $out = [];
        foreach (data_get($data, 'categories', []) as $cat) {
            if (! is_array($cat) || ! is_string($cat['name'] ?? null) || ! is_array($cat['items'] ?? null) || ! array_is_list($cat['items']) || count($cat['items']) > 300) {
                throw new RuntimeException('Vision response contained an invalid category.');
            }
            $items = [];
            foreach (data_get($cat, 'items', []) as $item) {
                if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                    throw new RuntimeException('Vision response contained an invalid dish.');
                }
                $rawVariations = $item['variations'] ?? [];
                if (! is_array($rawVariations) || ! array_is_list($rawVariations) || count($rawVariations) > 20) {
                    throw new RuntimeException('Vision response contained invalid variations.');
                }
                $name = trim((string) data_get($item, 'name', ''));
                if ($name === '') {
                    continue;
                }
                $variations = [];
                foreach ($rawVariations as $v) {
                    if (! is_array($v) || ! is_string($v['name'] ?? null)) {
                        throw new RuntimeException('Vision response contained an invalid variation.');
                    }
                    $vn = trim((string) data_get($v, 'name', ''));
                    if ($vn !== '') {
                        $variations[] = ['name' => $vn, 'price' => $this->num(data_get($v, 'price'))];
                    }
                }
                $items[] = [
                    'name' => $name,
                    'price' => $this->num(data_get($item, 'price')),
                    'is_veg' => $this->dietaryFlag($item['is_veg'] ?? null),
                    'variations' => $variations,
                ];
            }
            $name = trim((string) data_get($cat, 'name', '')) ?: 'Menu';
            if ($items) {
                $out[] = ['name' => $name, 'items' => $items];
            }
        }

        return $out;
    }

    private function dietaryFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        return null;
    }

    /** Invalid prices stay blank for owner review; never change their meaning. */
    private function num(mixed $v): ?float
    {
        if (! is_int($v) && ! is_float($v) && ! is_string($v)) {
            return null;
        }
        $text = trim((string) $v);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $text)) {
            return null;
        }
        $price = (float) $text;

        return is_finite($price) && $price <= 99999.99 ? round($price, 2) : null;
    }
}
