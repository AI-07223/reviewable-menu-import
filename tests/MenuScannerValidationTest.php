<?php

namespace Tests;

use App\Services\MenuScanner;
use Illuminate\Support\Facades\Http;

class MenuScannerValidationTest extends TestCase
{
    private function reply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    private function configureProviders(): void
    {
        config([
            'services.openrouter.key' => 'test',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.vision_model' => 'test',
            'services.mistral.key' => 'test',
            'services.mistral.vision_model' => 'test',
        ]);
        Http::preventStrayRequests();
    }

    public function test_malformed_json_and_invalid_schema_trigger_fallback(): void
    {
        foreach (['{"categories": BROKEN}', '{}', '{"categories":"invalid"}', '{"categories":[{"name":"Food","items":"invalid"}]}'] as $bad) {
            $this->configureProviders();
            Http::fake([
                'openrouter.ai/*' => Http::response($this->reply($bad)),
                'api.mistral.ai/*' => Http::response($this->reply('{"categories":[{"name":"Food","items":[{"name":"Rice","price":50,"is_veg":true}]}]}')),
            ]);
            $result = app(MenuScanner::class)->scan(['data:image/png;base64,test']);
            $this->assertSame('Rice', $result[0]['items'][0]['name']);
            Http::assertSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
        }
    }

    public function test_uncertain_dietary_values_and_invalid_prices_are_not_silently_corrected(): void
    {
        $this->configureProviders();
        $items = [
            ['name' => 'Chicken', 'price' => '-50', 'is_veg' => 'false'],
            ['name' => 'Unknown', 'price' => 'Rs 100', 'is_veg' => null],
            ['name' => 'Missing', 'price' => '10.50'],
            ['name' => 'Invalid', 'price' => '1.2.3', 'is_veg' => 'maybe'],
        ];
        Http::fake(['openrouter.ai/*' => Http::response($this->reply(json_encode(['categories' => [['name' => 'Food', 'items' => $items]]])))]);
        $result = app(MenuScanner::class)->scan(['data:image/png;base64,test'])[0]['items'];
        $this->assertFalse($result[0]['is_veg']);
        $this->assertNull($result[0]['price']);
        foreach ([1, 2, 3] as $i) {
            $this->assertNull($result[$i]['is_veg']);
        }
        $this->assertNull($result[1]['price']);
        $this->assertSame(10.5, $result[2]['price']);
        $this->assertNull($result[3]['price']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
    }

    public function test_valid_empty_menu_does_not_trigger_fallback(): void
    {
        $this->configureProviders();
        Http::fake(['openrouter.ai/*' => Http::response($this->reply('{"categories":[]}'))]);
        $this->assertSame([], app(MenuScanner::class)->scan(['data:image/png;base64,test']));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
    }
}
