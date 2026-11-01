<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ItemVariation;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

class ImportMenuTest extends TestCase
{
    use InteractsWithTenancy;

    // WP1.3 route split: /menu-import now lives only on TENANT subdomains
    // (routes/tenant.php) behind InitializeTenancyBySubdomain — and the product
    // schema (users, categories, menu_items, ...) only ever lived in the tenant
    // DB (P1's migration split). Provision a real tenant and hit it by subdomain
    // instead of RefreshDatabase on the bare default connection, same pattern as
    // TenancySpikeTest/ExampleTest.
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenancyDatabase();
        $this->initializeTestTenant('testing');
        tenancy()->end();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancyDatabase();
        parent::tearDown();
    }

    /** Prefix a path with the test tenant's subdomain — every route under test moved to routes/tenant.php. */
    private function tenantUrl(string $path): string
    {
        return 'http://testing.localhost'.$path;
    }

    /** Runs $callback inside the test tenant's DB context (fixture setup, or post-request DB assertions). */
    private function inTenant(\Closure $callback): mixed
    {
        return $this->tenancyTestTenant->run($callback);
    }

    private function actingAsOwner(): void
    {
        $this->actingAs($this->inTenant(fn () => User::factory()->create(['role' => 'owner'])));
    }

    /** A real, minimal 1x1 PNG — scan() now verifies the photo is decodable (L5 defense-in-depth). */
    private const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function fakeMistral(): void
    {
        config(['services.mistral.key' => 'test', 'services.mistral.vision_model' => 'pixtral']);
        Http::fake(['api.mistral.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'categories' => [
                    ['name' => 'Starters', 'items' => [
                        ['name' => 'Paneer Tikka', 'price' => 250, 'is_veg' => true, 'variations' => [['name' => 'Half', 'price' => 150], ['name' => 'Full', 'price' => 250]]],
                        ['name' => 'Chicken 65', 'price' => 280, 'is_veg' => false],
                    ]],
                    ['name' => 'Breads', 'items' => [['name' => 'Butter Naan', 'price' => 60, 'is_veg' => true]]],
                ],
            ])]]],
        ], 200)]);
    }

    /** Sets openrouter.* config so OpenRouter is attempted first (mirrors fakeMistral's config-then-fake pattern). */
    private function useOpenRouterConfig(): void
    {
        config(['services.openrouter.key' => 'test', 'services.openrouter.vision_model' => 'qwen/qwen3-vl-32b-instruct', 'services.openrouter.base_url' => 'https://openrouter.ai/api/v1']);
    }

    public function test_scan_prefers_openrouter_and_never_calls_mistral_when_openrouter_succeeds(): void
    {
        $this->actingAsOwner();
        config(['services.mistral.key' => 'test', 'services.mistral.vision_model' => 'pixtral']);
        $this->useOpenRouterConfig();
        // Both providers are faked so a stray call to Mistral would be observable via
        // assertNotSent below, rather than silently succeeding or failing to connect.
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'categories' => [
                        ['name' => 'Starters', 'items' => [
                            ['name' => 'Paneer Tikka', 'price' => 250, 'is_veg' => true, 'variations' => [['name' => 'Half', 'price' => 150], ['name' => 'Full', 'price' => 250]]],
                        ]],
                    ],
                ])]]],
            ], 200),
            'api.mistral.ai/*' => Http::response(['choices' => [['message' => ['content' => '{}']]]], 200),
        ]);

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertOk();
        $cats = $res->json('categories');
        $this->assertCount(1, $cats);
        $this->assertSame('Starters', $cats[0]['name']);
        $this->assertSame('Paneer Tikka', $cats[0]['items'][0]['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'openrouter.ai'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
    }

    public function test_scan_falls_back_to_mistral_after_openrouter_retry_exhausts(): void
    {
        $this->actingAsOwner();
        config(['services.mistral.key' => 'test', 'services.mistral.vision_model' => 'pixtral']);
        $this->useOpenRouterConfig();
        Http::fake([
            // Both attempts (initial + one retry) come back 429 — OpenRouter's retry exhausts.
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'categories' => [
                        ['name' => 'Starters', 'items' => [
                            ['name' => 'Paneer Tikka', 'price' => 250, 'is_veg' => true, 'variations' => [['name' => 'Half', 'price' => 150], ['name' => 'Full', 'price' => 250]]],
                            ['name' => 'Chicken 65', 'price' => 280, 'is_veg' => false],
                        ]],
                        ['name' => 'Breads', 'items' => [['name' => 'Butter Naan', 'price' => 60, 'is_veg' => true]]],
                    ],
                ])]]],
            ], 200),
        ]);

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertOk();
        $cats = $res->json('categories');
        $this->assertCount(2, $cats);
        $this->assertSame('Starters', $cats[0]['name']);
        $this->assertSame('Paneer Tikka', $cats[0]['items'][0]['name']);

        $this->assertCount(2, Http::recorded(fn ($request) => str_contains($request->url(), 'openrouter.ai')));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.mistral.ai'));
    }

    public function test_scan_returns_grouped_categories(): void
    {
        $this->actingAsOwner();
        $this->fakeMistral();

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertOk();
        $cats = $res->json('categories');
        $this->assertCount(2, $cats);
        $this->assertSame('Starters', $cats[0]['name']);
        $this->assertCount(2, $cats[0]['items']);
        $this->assertSame('Paneer Tikka', $cats[0]['items'][0]['name']);
        $this->assertCount(2, $cats[0]['items'][0]['variations']);
    }

    /**
     * Per-photo scanning means the wizard fires one /scan request per photo (plus retries) in
     * a single visit, so the route throttle was raised 3/min → 10/min (routes/tenant.php).
     * Confirm the new ceiling: 10 sequential single-image scans succeed, the 11th 429s.
     */
    public function test_scan_throttle_allows_ten_requests_then_blocks_the_eleventh(): void
    {
        $this->actingAsOwner();
        $this->fakeMistral();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson($this->tenantUrl('/menu-import/scan'), [
                'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
            ])->assertOk();
        }

        $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ])->assertStatus(429);
    }

    public function test_scan_rejects_non_image_payload(): void
    {
        $this->actingAsOwner();
        config(['services.mistral.key' => 'test']);

        $this->postJson($this->tenantUrl('/menu-import/scan'), ['images' => ['not-a-data-uri']])
            ->assertStatus(422);
    }

    /**
     * The photo already passed the decodability check by the time MenuScanner runs,
     * so a scan failure at that point is our side (both providers down/unconfigured/
     * rate-limited) — a clean 503 with a friendly, actionable message, never the
     * framework's default 500 page.
     */
    public function test_scan_returns_friendly_503_not_500_when_no_provider_is_configured(): void
    {
        $this->actingAsOwner();
        config(['services.openrouter.key' => null, 'services.mistral.key' => null]);

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertStatus(503);
        $this->assertNotEmpty($res->json('message'));
    }

    /** Both providers down (not merely unconfigured) also degrades to a 503, not a 500. */
    public function test_scan_returns_friendly_503_not_500_when_all_providers_fail(): void
    {
        $this->actingAsOwner();
        config(['services.mistral.key' => 'test', 'services.mistral.vision_model' => 'pixtral']);
        $this->useOpenRouterConfig();
        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'down']], 500),
            'api.mistral.ai/*' => Http::response('Internal Server Error', 500),
        ]);

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertStatus(503);
        $this->assertNotEmpty($res->json('message'));
    }

    /** A successful-but-empty read (blank/unreadable menu) is a 422 with a friendly, actionable message. */
    public function test_scan_returns_friendly_422_when_no_dishes_found(): void
    {
        $this->actingAsOwner();
        config(['services.mistral.key' => 'test', 'services.mistral.vision_model' => 'pixtral']);
        Http::fake(['api.mistral.ai/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode(['categories' => []])]]],
        ], 200)]);

        $res = $this->postJson($this->tenantUrl('/menu-import/scan'), [
            'images' => ['data:image/png;base64,'.self::TINY_PNG_B64],
        ]);

        $res->assertStatus(422);
        $this->assertNotEmpty($res->json('message'));
    }

    public function test_commit_creates_items_variations_and_skips_duplicates(): void
    {
        $this->actingAsOwner();

        // A pre-existing dish that should be skipped as a duplicate (case-insensitive).
        $existing = $this->inTenant(function () {
            $existing = Category::create(['name' => 'Starters', 'station' => 'Kitchen', 'is_active' => true, 'position' => 1]);
            MenuItem::create(['category_id' => $existing->id, 'name' => 'Paneer Tikka', 'price' => 200, 'gst_rate' => 5, 'is_veg' => true, 'is_active' => true, 'position' => 0]);

            return $existing;
        });

        $res = $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'paneer tikka', 'price' => 250, 'is_veg' => true, 'variations' => []], // duplicate → skipped
                    ['name' => 'Chicken 65', 'price' => 280, 'is_veg' => false, 'variations' => [['name' => 'Half', 'price' => 150], ['name' => 'Full', 'price' => 250]]],
                ]],
                ['name' => 'Breads', 'items' => [
                    ['name' => 'Butter Naan', 'price' => 60, 'is_veg' => true, 'variations' => []],
                    ['name' => '', 'price' => 0, 'is_veg' => true, 'variations' => []], // blank → ignored
                ]],
            ],
        ]);

        // Response separates duplicate skips from no-price skips (Wave 2: distinct owner-facing reasons).
        $res->assertOk()->assertJson(['created' => 2, 'skipped_duplicate' => 1, 'skipped_no_price' => 0, 'categories' => 2]);

        $this->inTenant(function () use ($existing) {
            // Duplicate skipped: still only one Paneer Tikka in Starters.
            $this->assertSame(1, MenuItem::where('category_id', $existing->id)->whereRaw('LOWER(name) = ?', ['paneer tikka'])->count());
            $this->assertDatabaseHas('menu_items', ['name' => 'Chicken 65', 'is_veg' => false]);
            $this->assertDatabaseHas('menu_items', ['name' => 'Butter Naan']);

            // Breads category firstOrCreate'd with Kitchen station.
            $this->assertDatabaseHas('categories', ['name' => 'Breads', 'station' => 'Kitchen']);

            // Chicken 65 got its Half/Full variations persisted.
            $this->assertSame(2, ItemVariation::where('menu_item_id', MenuItem::where('name', 'Chicken 65')->value('id'))->count());
        });
    }

    /**
     * Regression for per-photo scanning: MenuScanner's cross-photo lowercase category merge
     * only runs WITHIN one photo's scan now, not across photos, so the commit payload can
     * legitimately contain "Starters" (from photo 1) and "STARTERS" (from photo 2). The
     * case-insensitive category lookup in commit() must still fold them into one row.
     */
    public function test_commit_merges_categories_case_insensitively_across_photos(): void
    {
        $this->actingAsOwner();

        $res = $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'Paneer Tikka', 'price' => 250, 'is_veg' => true, 'variations' => []],
                ]],
                ['name' => 'STARTERS', 'items' => [
                    // Same dish, different case and price — merged into the same category and
                    // skipped as a duplicate there (case-insensitive item dedupe, unchanged).
                    ['name' => 'paneer tikka', 'price' => 300, 'is_veg' => true, 'variations' => []],
                ]],
            ],
        ]);

        $res->assertOk()->assertJson(['created' => 1, 'skipped_duplicate' => 1, 'skipped_no_price' => 0]);

        $this->inTenant(function () {
            $this->assertSame(1, Category::whereRaw('LOWER(name) = ?', ['starters'])->count());
        });
    }

    public function test_commit_skips_zero_or_blank_price_items(): void
    {
        $this->actingAsOwner();

        $res = $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'Free Sample', 'price' => 0, 'is_veg' => true, 'variations' => []],
                    ['name' => 'Water', 'price' => '', 'is_veg' => true, 'variations' => []],
                    ['name' => 'Chicken 65', 'price' => 280, 'is_veg' => false, 'variations' => []],
                ]],
            ],
        ]);

        $res->assertOk()->assertJson(['created' => 1, 'skipped_duplicate' => 0, 'skipped_no_price' => 2, 'categories' => 1]);
        $this->inTenant(function () {
            $this->assertDatabaseMissing('menu_items', ['name' => 'Free Sample']);
            $this->assertDatabaseMissing('menu_items', ['name' => 'Water']);
            $this->assertDatabaseHas('menu_items', ['name' => 'Chicken 65']);
        });
    }

    public function test_commit_rejects_negative_price(): void
    {
        $this->actingAsOwner();

        $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'Weird Item', 'price' => -50, 'is_veg' => true, 'variations' => []],
                ]],
            ],
        ])->assertStatus(422);

        $this->inTenant(fn () => $this->assertDatabaseMissing('menu_items', ['name' => 'Weird Item']));
    }

    public function test_commit_rejects_overlong_name_with_friendly_422_not_500(): void
    {
        $this->actingAsOwner();

        $longName = str_repeat('a', 300);

        $res = $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => $longName, 'price' => 100, 'is_veg' => true, 'variations' => []],
                ]],
            ],
        ]);

        $res->assertStatus(422);
        $this->inTenant(fn () => $this->assertDatabaseMissing('menu_items', ['price' => 100]));
    }

    public function test_commit_applies_chosen_gst_rate(): void
    {
        $this->actingAsOwner();

        $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'gst_rate' => 18,
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'Hotel Thali', 'price' => 350, 'is_veg' => true, 'variations' => []],
                ]],
            ],
        ])->assertOk();

        $this->inTenant(fn () => $this->assertDatabaseHas('menu_items', ['name' => 'Hotel Thali', 'gst_rate' => 18]));
    }

    public function test_commit_defaults_gst_rate_to_five_when_omitted(): void
    {
        $this->actingAsOwner();

        $this->postJson($this->tenantUrl('/menu-import/commit'), [
            'categories' => [
                ['name' => 'Starters', 'items' => [
                    ['name' => 'Plain Item', 'price' => 100, 'is_veg' => true, 'variations' => []],
                ]],
            ],
        ])->assertOk();

        $this->inTenant(fn () => $this->assertDatabaseHas('menu_items', ['name' => 'Plain Item', 'gst_rate' => 5]));
    }

    public function test_menu_import_page_is_owner_only(): void
    {
        // Unauthenticated → redirect to login.
        $this->get($this->tenantUrl('/menu-import'))->assertRedirect($this->tenantUrl('/login'));

        // A cashier is not the owner → bounced off the page.
        $this->actingAs($this->inTenant(fn () => User::factory()->create(['role' => 'cashier'])));
        $this->get($this->tenantUrl('/menu-import'))->assertRedirect();
    }

    public function test_commit_requires_explicit_dietary_classification(): void
    {
        $this->actingAsOwner();
        foreach ([null, 'false', 'maybe'] as $flag) {
            $this->postJson($this->tenantUrl('/menu-import/commit'), [
                'categories' => [['name' => 'Food', 'items' => [
                    ['name' => 'Unreviewed', 'price' => 50, 'is_veg' => $flag],
                ]]],
            ])->assertStatus(422);
        }
        $this->inTenant(fn () => $this->assertDatabaseMissing('menu_items', ['name' => 'Unreviewed']));
    }
}
