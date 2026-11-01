<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\GettingStartedWidget;
use App\Models\Setting;
use App\Models\User;
use App\Services\OnboardingState;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Backend-side onboarding fixes (BE-1..BE-8): the shared /welcome/logo +
 * /welcome/theme contract, the checklist widget's live-derived visibility,
 * and the 422-on-validation-failure guard. Same tenancy scaffolding as
 * ImportMenuTest/ManageUpiDemoLockTest — product tables only ever live in a
 * real (migrated) tenant DB, never the bare central connection.
 */
class OnboardingTest extends TestCase
{
    use InteractsWithTenancy;

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

    /** Prefix a path with the test tenant's subdomain — onboarding routes live on routes/tenant.php. */
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

    /**
     * A small multi-hue PNG built with real GD (three saturated, equal-sized
     * colour blocks) so ColorExtractor::palette() has real distinct hue
     * buckets to rank — a plain UploadedFile::fake()->image() is a solid
     * black square and would yield zero candidates.
     */
    private function multiHueLogo(): UploadedFile
    {
        $img = imagecreatetruecolor(30, 30);
        imagefilledrectangle($img, 0, 0, 9, 29, imagecolorallocate($img, 220, 20, 20));    // red
        imagefilledrectangle($img, 10, 0, 19, 29, imagecolorallocate($img, 20, 130, 220)); // blue
        imagefilledrectangle($img, 20, 0, 29, 29, imagecolorallocate($img, 20, 200, 60));  // green

        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        $path = tempnam(sys_get_temp_dir(), 'onb_logo_').'.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    /** BE-2/BE-7: /welcome/logo ranks candidates but never touches accent_color (that's /welcome/theme's job alone). */
    public function test_logo_upload_returns_ranked_candidates_and_never_writes_accent_color(): void
    {
        $this->actingAsOwner();

        $response = $this->post($this->tenantUrl('/welcome/logo'), [
            'logo' => $this->multiHueLogo(),
        ]);

        $response->assertOk()->assertJsonStructure(['logo_url', 'accent', 'candidates']);

        $candidates = $response->json('candidates');
        $this->assertIsArray($candidates);
        $this->assertNotEmpty($candidates, 'a multi-hue logo must yield at least one ranked accent candidate');
        $this->assertSame($candidates[0], $response->json('accent'), 'accent must be candidates[0]');

        $this->inTenant(function () {
            $this->assertNull(Setting::current()->accent_color, '/welcome/logo must persist logo_path only, never accent_color');
        });
    }

    /** BE-2: /welcome/theme is still the ONLY endpoint that writes accent_color. */
    public function test_theme_endpoint_persists_accent_color(): void
    {
        $this->actingAsOwner();

        $this->post($this->tenantUrl('/welcome/theme'), ['accent' => '#3355ff'])->assertOk();

        $this->inTenant(function () {
            $this->assertSame('#3355ff', Setting::current()->accent_color);
        });
    }

    /** BE-3: the checklist must not vanish forever once finish() runs, if progress is genuinely partial. */
    public function test_getting_started_widget_stays_visible_with_partial_progress_after_finish(): void
    {
        $this->inTenant(function () {
            User::factory()->create(['role' => 'owner']);

            $onboarding = app(OnboardingState::class);
            $onboarding->start();
            $onboarding->complete(); // owner hit "Done" despite skipping every step

            $this->assertFalse($onboarding->isComplete(), 'sanity: no menu/logo/business/staff was actually set up');
            $this->assertTrue(GettingStartedWidget::canView(), 'a completed-but-incomplete tenant must keep seeing the checklist');
        });
    }

    // Before this fix, an owner who left /welcome mid-setup (or dismissed the
    // checklist and came back) had no visible way back into the wizard — every
    // "next" click had to be discovered via URL guessing. The checklist widget now
    // always carries a "Continue setup" link straight back to /welcome (which itself
    // resumes at OnboardingState's current_step, no query param needed).
    public function test_getting_started_widget_shows_a_resume_link_to_welcome_while_incomplete(): void
    {
        // Livewire::test() doesn't go through HTTP routing/middleware (no tenant
        // subdomain to resolve), so — same pattern as ManageUpiDemoLockTest — wrap it
        // in ->run() to hit the tenant DB the widget's getViewData() queries.
        $this->inTenant(function () {
            $this->actingAs(User::factory()->create(['role' => 'owner']));

            Livewire::test(GettingStartedWidget::class)
                ->assertSee('Continue setup')
                ->assertSee('/welcome', false);
        });
    }

    /** BE-4: business progress must count the UPI fields saveBusiness() actually writes, not just address/hours/phone. */
    public function test_progress_business_is_true_when_only_upi_id_is_set(): void
    {
        $this->inTenant(function () {
            Setting::current()->update(['upi_id' => 'restaurant@okhdfcbank']);

            $this->assertTrue(app(OnboardingState::class)->progress()['business']);
        });
    }

    /** BE-1: a /welcome/* validation failure must render as 422 JSON, not a 302 the island silently treats as success. */
    public function test_welcome_validation_failure_returns_422_json_when_client_expects_json(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson($this->tenantUrl('/welcome/theme'), ['accent' => 'not-a-hex-colour']);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['accent']]);
    }
}
