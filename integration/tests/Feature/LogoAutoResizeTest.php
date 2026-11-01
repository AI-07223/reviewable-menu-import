<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ManageUpi;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenancy;
use Tests\TestCase;

/**
 * Logo auto-resize (server-side, GD): every uploaded logo is downscaled to
 * <=512px on the long edge (never upscaled), alpha-safe, and re-encoded as PNG
 * via App\Support\LogoProcessor before it ever touches disk — see that class
 * and OnboardingController::uploadLogo()/ManageUpi::save(). The old 2MB upload
 * ceiling is gone (now 20MB); LogoProcessor is what keeps the STORED file
 * small, not the upload limit.
 */
class LogoAutoResizeTest extends TestCase
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

    private function tenantUrl(string $path): string
    {
        return 'http://testing.localhost'.$path;
    }

    private function inTenant(\Closure $callback): mixed
    {
        return $this->tenancyTestTenant->run($callback);
    }

    private function actingAsOwner(): void
    {
        $this->actingAs($this->inTenant(fn () => User::factory()->create(['role' => 'owner'])));
    }

    /** A real, GD-encoded solid-fill PNG of the given size. */
    private function pngUpload(int $w, int $h, string $name = 'logo.png'): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 200, 40, 40));

        return $this->uploadFromGd($img, $name);
    }

    /** Top half fully transparent, bottom half opaque red — for the alpha-preservation assertion. */
    private function alphaPngUpload(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefilledrectangle($img, 0, 0, $w - 1, (int) ($h / 2) - 1, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagefilledrectangle($img, 0, (int) ($h / 2), $w - 1, $h - 1, imagecolorallocatealpha($img, 200, 40, 40, 0));

        return $this->uploadFromGd($img, 'alpha-logo.png');
    }

    /**
     * A genuinely incompressible >2MB PNG built from random pixel data via a
     * raw 24bpp BMP — random_bytes() over the whole buffer is a single fast C
     * call, so this avoids a slow per-pixel PHP loop while still producing
     * pixels dense enough that PNG's DEFLATE can't shrink them below 2MB.
     */
    private function noisePngUpload(int $w, int $h): UploadedFile
    {
        $rowSize = (int) floor((24 * $w + 31) / 32) * 4;
        $pixelDataSize = $rowSize * $h;
        $header = pack('a2VvvV', 'BM', 54 + $pixelDataSize, 0, 0, 54);
        $dib = pack('VVVvvVVVVVV', 40, $w, $h, 1, 24, 0, $pixelDataSize, 2835, 2835, 0, 0);

        $rowPad = $rowSize - $w * 3;
        $pixels = '';
        for ($y = 0; $y < $h; $y++) {
            $pixels .= random_bytes($w * 3).str_repeat("\0", $rowPad);
        }

        $bmpPath = tempnam(sys_get_temp_dir(), 'noise_').'.bmp';
        file_put_contents($bmpPath, $header.$dib.$pixels);
        $img = imagecreatefrombmp($bmpPath);
        @unlink($bmpPath);

        return $this->uploadFromGd($img, 'noise-logo.png');
    }

    /**
     * A valid PNG signature + IHDR chunk (so getimagesize() reports real
     * dimensions and Laravel's `image` rule passes it through) but with the
     * IDAT/IEND chunks stripped — GD can't fully decode it. Exercises
     * LogoProcessor's own decode guard, not just Laravel's built-in rule.
     */
    private function truncatedPngUpload(): UploadedFile
    {
        $img = imagecreatetruecolor(10, 10);
        imagefilledrectangle($img, 0, 0, 9, 9, imagecolorallocate($img, 100, 100, 100));
        ob_start();
        imagepng($img);
        $full = (string) ob_get_clean();
        imagedestroy($img);

        $truncated = substr($full, 0, 33); // 8-byte signature + 25-byte IHDR chunk only
        $path = tempnam(sys_get_temp_dir(), 'trunc_').'.png';
        file_put_contents($path, $truncated);

        return new UploadedFile($path, 'broken.png', 'image/png', null, true);
    }

    /** @param  \GdImage  $img */
    private function uploadFromGd($img, string $name): UploadedFile
    {
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $path = tempnam(sys_get_temp_dir(), 'logo_').'.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_large_logo_is_downscaled_to_512px_preserving_aspect_ratio(): void
    {
        $this->actingAsOwner();

        $this->post($this->tenantUrl('/welcome/logo'), ['logo' => $this->pngUpload(2000, 1500)])->assertOk();

        $this->inTenant(function () {
            $path = Setting::current()->logo_path;
            $this->assertStringEndsWith('.png', $path);

            $img = imagecreatefromstring(Storage::disk('public')->get($path));
            $this->assertNotFalse($img);
            $this->assertSame(512, imagesx($img));
            $this->assertSame(384, imagesy($img), 'aspect ratio must be preserved (2000x1500 -> 512x384)');
            imagedestroy($img);
        });
    }

    public function test_small_logo_is_not_upscaled(): void
    {
        $this->actingAsOwner();

        $this->post($this->tenantUrl('/welcome/logo'), ['logo' => $this->pngUpload(200, 100)])->assertOk();

        $this->inTenant(function () {
            $img = imagecreatefromstring(Storage::disk('public')->get(Setting::current()->logo_path));
            $this->assertSame(200, imagesx($img));
            $this->assertSame(100, imagesy($img));
            imagedestroy($img);
        });
    }

    public function test_transparency_is_preserved(): void
    {
        $this->actingAsOwner();

        $this->post($this->tenantUrl('/welcome/logo'), ['logo' => $this->alphaPngUpload(100, 100)])->assertOk();

        $this->inTenant(function () {
            $img = imagecreatefromstring(Storage::disk('public')->get(Setting::current()->logo_path));
            $rgba = imagecolorat($img, 10, 10); // inside the fully-transparent top half
            $alpha = ($rgba >> 24) & 0x7F; // GD: 0 = opaque, 127 = fully transparent
            imagedestroy($img);
            $this->assertSame(127, $alpha, 'the transparent region must stay transparent after resize/re-encode');
        });
    }

    public function test_a_file_over_2mb_now_passes_validation(): void
    {
        $this->actingAsOwner();

        $upload = $this->noisePngUpload(1200, 1200);
        $this->assertGreaterThan(2 * 1024 * 1024, $upload->getSize(), 'sanity: the fixture must actually exceed the old 2MB cap');

        $this->post($this->tenantUrl('/welcome/logo'), ['logo' => $upload])->assertOk();
    }

    public function test_an_undecodable_file_returns_a_clean_422_not_a_500(): void
    {
        $this->actingAsOwner();

        $response = $this->postJson($this->tenantUrl('/welcome/logo'), ['logo' => $this->truncatedPngUpload()]);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['logo']]);
    }

    // ManageUpi (Settings page) gets the identical LogoProcessor treatment — this
    // is the "lighter" check the Livewire harness allows: one round trip through
    // set()/call('save') proving the wiring (validate -> LogoProcessor -> disk)
    // works, without re-covering every LogoProcessor case already proven above.
    // Livewire's test ->set() upload path reads ->name off the file (see
    // SupportTesting/Testable::upload()), which only Illuminate\Http\Testing\File
    // (UploadedFile::fake()) carries — a plain UploadedFile 404s there, so this
    // one test uses fake() instead of our own GD helper.
    public function test_manage_upi_resizes_and_stores_the_logo(): void
    {
        $this->tenancyTestTenant->run(function () {
            $this->actingAs(User::factory()->create(['role' => 'owner']));

            Livewire::test(ManageUpi::class)
                ->set('logo', UploadedFile::fake()->image('logo.jpg', 2000, 1500))
                ->call('save');

            $path = Setting::current()->logo_path;
            $this->assertNotNull($path);
            $this->assertStringEndsWith('.png', $path);

            $img = imagecreatefromstring(Storage::disk('public')->get($path));
            $this->assertNotFalse($img);
            $this->assertLessThanOrEqual(512, max(imagesx($img), imagesy($img)));
            imagedestroy($img);
        });
    }

    public function test_failed_logo_write_preserves_existing_branding(): void
    {
        $this->actingAsOwner();
        $this->inTenant(fn () => Setting::current()->update(['logo_path' => 'logos/old.png', 'accent_color' => '#123456']));
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldNotReceive('delete');
        Storage::partialMock()->shouldReceive('disk')->with('public')->andReturn($disk);

        $this->postJson($this->tenantUrl('/welcome/logo'), ['logo' => $this->pngUpload(100, 100)])
            ->assertStatus(422)->assertJsonValidationErrors('logo');
        $this->inTenant(function () {
            $this->assertSame('logos/old.png', Setting::current()->logo_path);
            $this->assertSame('#123456', Setting::current()->accent_color);
        });
    }

    public function test_settings_failed_logo_write_preserves_existing_branding(): void
    {
        $this->tenancyTestTenant->run(function () {
            $this->actingAs(User::factory()->create(['role' => 'owner']));
            Setting::current()->update(['logo_path' => 'logos/old.png', 'accent_color' => '#123456']);
            $component = Livewire::test(ManageUpi::class)
                ->set('logo', UploadedFile::fake()->image('logo.jpg', 100, 100));
            $disk = \Mockery::mock(FilesystemAdapter::class);
            $disk->shouldReceive('put')->once()->andReturn(false);
            $disk->shouldNotReceive('delete');
            Storage::set('public', $disk);
            $component->call('save')->assertHasErrors('logo');
            $this->assertSame('logos/old.png', Setting::current()->logo_path);
            $this->assertSame('#123456', Setting::current()->accent_color);
        });
    }
}
