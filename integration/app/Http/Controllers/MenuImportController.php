<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ItemVariation;
use App\Models\MenuItem;
use App\Services\MenuScanner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class MenuImportController extends Controller
{
    /**
     * Quick pre-decode length guard per photo (base64 runs ~1.33x the raw bytes) — a cheap
     * reject before we spend CPU decoding. The real budget is the total decoded-bytes cap
     * enforced below (H3 defense-in-depth; the route itself is already throttled 3/min and
     * owner-only).
     */
    private const MAX_DATA_URI = 6 * 1024 * 1024;

    /** The full-screen React wizard shell. */
    public function show()
    {
        return view('menu-import');
    }

    /** Step 1 → 2: read the uploaded photos with the vision model. */
    public function scan(Request $request)
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'string'],
        ]);

        // H3 defense-in-depth: cap total decoded bytes across all photos in the request
        // (not just each one individually) so a handful of large-but-under-the-per-image-limit
        // photos can't add up to an oversized vision-API payload.
        $maxTotalBytes = (int) env('MENU_IMPORT_MAX_BYTES', 4 * 1024 * 1024);
        $totalBytes = 0;

        foreach ($data['images'] as $uri) {
            $comma = strpos($uri, ',');
            if (! str_starts_with($uri, 'data:image/') || $comma === false || strlen($uri) > self::MAX_DATA_URI) {
                return response()->json(['message' => 'One of those photos is too large or not a photo — try a smaller, clearer shot.'], 422);
            }

            // L5: confirm it's actually a decodable image, not just a suspicious data-URI prefix.
            $raw = base64_decode(substr($uri, $comma + 1), true);
            if ($raw === false || @getimagesizefromstring($raw) === false) {
                return response()->json(['message' => "One of those files isn't a readable photo — try a clearer shot."], 422);
            }

            $totalBytes += strlen($raw);
            if ($totalBytes > $maxTotalBytes) {
                return response()->json(['message' => 'Those photos are too large together — try fewer photos or smaller shots.'], 422);
            }
        }

        try {
            $categories = app(MenuScanner::class)->scan($data['images']);
        } catch (\Throwable $e) {
            Log::warning('Menu import scan failed: '.$e->getMessage());

            // The photo itself already passed the decodability check above, so a
            // failure here is on our side (both vision providers down/unconfigured/
            // rate-limited, or an unparsable model reply) — 503, not a 422, and
            // never the framework's default 500 page.
            return response()->json([
                'message' => "Couldn't read that menu photo — try a clearer, straight-on photo, or add items manually.",
            ], 503);
        }

        if (! array_sum(array_map(fn ($c) => count($c['items'] ?? []), $categories))) {
            return response()->json([
                'message' => 'No dishes found in that photo — try a clearer, straight-on photo, or add items manually.',
            ], 422);
        }

        return response()->json(['categories' => $categories]);
    }

    /** Step 2 → 3: persist the reviewed categories & dishes. */
    public function commit(Request $request)
    {
        // Built with Validator::make (not $request->validate()) so a failure always
        // returns JSON here — this endpoint sits outside the app's api/* prefix, and
        // the framework's default validation-exception handling would otherwise
        // redirect instead of responding with the friendly error the wizard expects.
        $validator = Validator::make($request->all(), [
            'categories' => ['required', 'array', 'max:100'],
            'categories.*.name' => ['nullable', 'string', 'max:255'],
            'categories.*.items' => ['array', 'max:300'],
            // Blank rows are tolerated and filtered out below (a dish needs a name to import).
            'categories.*.items.*.name' => ['nullable', 'string', 'max:255'],
            'categories.*.items.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'categories.*.items.*.is_veg' => ['required', 'boolean'],
            'categories.*.items.*.variations' => ['nullable', 'array', 'max:20'],
            'categories.*.items.*.variations.*.name' => ['nullable', 'string', 'max:255'],
            'categories.*.items.*.variations.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'gst_rate' => ['nullable', 'numeric', 'in:0,5,12,18'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => "We couldn't save some items. Please check the names and prices and try again."], 422);
        }

        $data = $validator->validated();
        $gstRate = $data['gst_rate'] ?? 5;

        $created = 0;
        $skippedDuplicate = 0;
        $skippedNoPrice = 0;
        $cats = 0;

        try {
            DB::transaction(function () use ($data, $gstRate, &$created, &$skippedDuplicate, &$skippedNoPrice, &$cats) {
                foreach ($data['categories'] as $cat) {
                    $items = array_values(array_filter($cat['items'] ?? [], fn ($i) => trim($i['name'] ?? '') !== ''));
                    if (! $items) {
                        continue;
                    }
                    $cats++;

                    // Case-insensitive lookup: per-photo scanning means MenuScanner's cross-photo
                    // lowercase category merge no longer runs across photos, so an exact-match
                    // firstOrCreate would let "Starters" (photo 1) and "STARTERS" (photo 2) fragment
                    // into two categories on Postgres (case-sensitive equality).
                    $catName = trim($cat['name'] ?? '') ?: 'Menu';
                    $category = Category::whereRaw('LOWER(name) = ?', [mb_strtolower($catName)])->first()
                        ?? Category::create([
                            'name' => $catName,
                            'station' => 'Kitchen',
                            'is_active' => true,
                            'position' => (Category::max('position') ?? 0) + 1,
                        ]);

                    foreach ($items as $r) {
                        $name = trim($r['name']);
                        $price = (float) ($r['price'] ?? 0);

                        // Free (0.00) or negative prices would go live and orderable — skip,
                        // and tell the owner it's a price problem (not a duplicate) so they
                        // know to fix the price and re-import.
                        if ($price <= 0) {
                            $skippedNoPrice++;

                            continue;
                        }

                        if (MenuItem::where('category_id', $category->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                            $skippedDuplicate++;

                            continue;
                        }

                        $item = MenuItem::create([
                            'category_id' => $category->id,
                            'name' => $name,
                            'price' => $price,
                            'gst_rate' => $gstRate,
                            'is_veg' => (bool) $r['is_veg'],
                            'is_active' => true,
                            'position' => 0,
                        ]);

                        foreach (array_values($r['variations'] ?? []) as $pos => $v) {
                            $vn = trim((string) ($v['name'] ?? ''));
                            $vp = (float) ($v['price'] ?? 0);
                            if ($vn !== '' && $vp > 0) {
                                ItemVariation::create([
                                    'menu_item_id' => $item->id,
                                    'name' => $vn,
                                    'price' => $vp,
                                    'position' => $pos + 1,
                                ]);
                            }
                        }
                        $created++;
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('Menu import commit failed: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => "We couldn't save some items. Please check the names and prices and try again."], 422);
        }

        return response()->json([
            'created' => $created,
            'skipped_duplicate' => $skippedDuplicate,
            'skipped_no_price' => $skippedNoPrice,
            'categories' => $cats,
        ]);
    }
}
