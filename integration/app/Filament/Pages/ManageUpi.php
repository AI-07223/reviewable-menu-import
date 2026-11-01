<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\ColorExtractor;
use App\Support\LogoProcessor;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

class ManageUpi extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.manage-upi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $navigationLabel = 'Website & Payments';

    protected static ?string $title = 'Website & Payments';

    public array $f = [];

    protected array $fields = [
        'upi_id', 'upi_name', 'gstin', 'tagline', 'about_story', 'founder_message',
        'phone', 'whatsapp', 'instagram', 'facebook', 'hours', 'address', 'map_embed',
    ];

    public bool $online_ordering = true;

    public bool $delivery_enabled = true;

    public $logo = null;            // freshly picked upload (temporary)

    public ?string $currentLogo = null;   // public URL of the saved logo, for preview

    public ?string $accentColor = null;   // extracted hex, for the swatch

    public function mount(): void
    {
        $s = Setting::current();
        foreach ($this->fields as $key) {
            $this->f[$key] = $s->{$key} ?? '';
        }
        $this->online_ordering = (bool) $s->online_ordering;
        $this->delivery_enabled = (bool) $s->delivery_enabled;
        $this->currentLogo = $s->logo_path ? $this->logoUrl($s->logo_path) : null;
        $this->accentColor = $s->accent_color;
    }

    public function save(): void
    {
        // 20MB is a generous ceiling on the raw upload, not the stored size — it's
        // downscaled to ≤512px and re-encoded via LogoProcessor below, so even a
        // big phone photo lands on disk as a lean PNG (tens of KB).
        $this->validate(['logo' => 'nullable|image|max:20480']);

        $data = [];
        foreach ($this->fields as $key) {
            $data[$key] = trim((string) ($this->f[$key] ?? '')) ?: null;
        }
        $data['online_ordering'] = $this->online_ordering;
        $data['delivery_enabled'] = $this->delivery_enabled;

        // Demo lock (FIX 4): the logo is the most destructive branding surface —
        // it rebrands every screen (admin, till, storefront, receipts) and
        // drives the derived accent colour, so a vandalized upload is visible to
        // every demo visitor until the next reset. Discard it before it ever
        // touches disk; text branding (tagline/story) stays editable so the
        // demo still feels responsive. Same per-tenant key as the field freeze below.
        if ($this->logo && \Tenancy::isDemo()) {
            $this->logo = null;
        }

        $newLogoPath = null;
        $oldLogoPath = Setting::current()->logo_path;

        // New logo → resize, store on the public disk, and derive the accent colour from it.
        if ($this->logo) {
            $resized = LogoProcessor::resize($this->logo);
            $path = 'logos/'.Str::random(40).'.png';
            if (! Storage::disk('public')->put($path, $resized)) {
                throw ValidationException::withMessages([
                    'logo' => 'The logo could not be saved. Please try again.',
                ]);
            }
            $newLogoPath = $path;
            $data['logo_path'] = $path;
            $data['accent_color'] = ColorExtractor::dominant(Storage::disk('public')->path($path)) ?? Setting::current()->accent_color;
        }

        // Freeze payment/contact/social fields off the CURRENT TENANT's is_demo flag, not
        // the session — a visitor who logs in normally (no /demo-login) on the public demo
        // tenant must still be unable to change the UPI VPA. Per-tenant (Tenancy::isDemo()):
        // only the special demo tenant freezes; a real tenant never does.
        if (\Tenancy::isDemo()) {
            $frozen = ['upi_id', 'upi_name', 'map_embed', 'phone', 'whatsapp', 'instagram', 'facebook'];
            foreach ($frozen as $key) {
                unset($data[$key]);
            }
        }

        try {
            Setting::current()->update($data);
        } catch (\Throwable $e) {
            if ($newLogoPath) {
                Storage::disk('public')->delete($newLogoPath);
            }
            throw $e;
        }

        if ($newLogoPath) {
            $this->currentLogo = $this->logoUrl($newLogoPath);
            $this->accentColor = $data['accent_color'];
            $this->logo = null;
            if ($oldLogoPath && $oldLogoPath !== $newLogoPath) {
                Storage::disk('public')->delete($oldLogoPath);
            }
        }

        if (\Tenancy::isDemo()) {
            Notification::make()
                ->title('Branding saved')
                ->body('Branding saved. Payment & contact details are locked in demo mode.')
                ->warning()
                ->send();
        } else {
            Notification::make()->title('Saved')->body('Website content and payment settings updated.')->success()->send();
        }
    }

    // WP1.4: the public disk is tenant-suffixed, so a logo upload's URL must go through
    // tenant_asset() whenever a tenant is bound. Falls back to the plain disk URL outside
    // tenant context so nothing breaks before the route split (WP1.3) lands.
    private function logoUrl(string $path): string
    {
        return \Tenancy::check() ? tenant_asset($path) : Storage::disk('public')->url($path);
    }
}
