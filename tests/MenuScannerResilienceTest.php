<?php

namespace Tests;

use App\Services\MenuScanner;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MenuScannerResilienceTest extends TestCase
{
    private function providers(): void
    {
        config(['services.openrouter.key' => 'test', 'services.openrouter.base_url' => 'https://openrouter.ai/api/v1', 'services.openrouter.vision_model' => 'test', 'services.mistral.key' => 'test', 'services.mistral.vision_model' => 'test']);
        Http::preventStrayRequests();
    }

    private function reply(string $name = 'Rice', int $price = 50): array
    {
        return ['choices' => [['message' => ['content' => json_encode(['categories' => [['name' => 'Food', 'items' => [['name' => $name, 'price' => $price, 'is_veg' => true]]]]])]]]];
    }

    public function test_rate_limit_retries_primary_before_using_fallback(): void
    {
        $this->providers();
        Http::fake(['openrouter.ai/*' => Http::sequence()->push([], 429)->push($this->reply())]);
        $this->assertSame('Rice', app(MenuScanner::class)->scan(['data:image/png;base64,test'])[0]['items'][0]['name']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mistral.ai'));
    }

    public function test_permanent_primary_failure_uses_fallback_without_retry(): void
    {
        $this->providers();
        Http::fake(['openrouter.ai/*' => Http::response([], 401), 'api.mistral.ai/*' => Http::response($this->reply())]);
        $this->assertCount(1, app(MenuScanner::class)->scan(['data:image/png;base64,test']));
        Http::assertSentCount(2);
    }

    public function test_all_provider_failure_is_not_reported_as_an_empty_menu(): void
    {
        $this->providers();
        Http::fake(['openrouter.ai/*' => Http::response([], 401), 'api.mistral.ai/*' => Http::response([], 503)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Mistral request failed (503)');
        app(MenuScanner::class)->scan(['data:image/png;base64,test']);
    }

    public function test_merge_keeps_first_case_insensitive_match_across_images(): void
    {
        $this->providers();
        Http::fake(['openrouter.ai/*' => Http::sequence()->push($this->reply())->push($this->reply('RICE', 99))]);
        $result = app(MenuScanner::class)->scan(['data:image/png;base64,one', 'data:image/png;base64,two']);
        $this->assertCount(1, $result[0]['items']);
        $this->assertSame(50.0, $result[0]['items'][0]['price']);
    }

    public function test_missing_configuration_fails_before_network_request(): void
    {
        Http::preventStrayRequests();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Menu scanning is not configured');
        app(MenuScanner::class)->scan(['data:image/png;base64,test']);
    }
}
