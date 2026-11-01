<?php

namespace App\Support;

// Pull the dominant *saturated* colour out of a logo so the storefront can theme
// itself. Downscales with GD, buckets pixels by hue, ignores near-white / near-black
// / greyish pixels (backgrounds, text, shadows), returns the winning bucket as hex.
// Defensive: returns null on any failure so callers can just fall back to the brand.
class ColorExtractor
{
    public static function dominant(string $absolutePath): ?string
    {
        return self::palette($absolutePath, 1)[0] ?? null;
    }

    /**
     * Rank the image's hue buckets by weight (saturation-summed) and return up to
     * $max hex strings, most dominant first. Empty array on any GD failure or a
     * logo that's essentially greyscale (no bucket cleared the saturation floor).
     *
     * @return list<string>
     */
    public static function palette(string $absolutePath, int $max = 5): array
    {
        $bucketed = self::bucketize($absolutePath);
        if ($bucketed === null) {
            return [];
        }

        [$weight, $best] = $bucketed;

        $ranked = [];
        foreach ($weight as $i => $wsum) {
            if ($wsum > 0 && $best[$i] !== null) {
                $ranked[] = [$wsum, $best[$i]];
            }
        }

        usort($ranked, fn ($a, $b) => $b[0] <=> $a[0]);

        return array_map(
            fn ($entry) => sprintf('#%02x%02x%02x', $entry[1][1], $entry[1][2], $entry[1][3]),
            array_slice($ranked, 0, $max),
        );
    }

    /**
     * Downscale the image and bucket its saturated pixels into 12 hue buckets.
     * Returns [weight, best] (weight = summed saturation per bucket, best = the
     * most-saturated [sat, r, g, b] representative per bucket) or null on any
     * GD failure. Extracted from the original single-colour dominant() so both
     * dominant() and palette() share the exact same sampling/weighting logic.
     *
     * @return array{0: array<int,float>, 1: array<int,array{0:float,1:int,2:int,3:int}|null>}|null
     */
    private static function bucketize(string $absolutePath): ?array
    {
        if (! is_file($absolutePath) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            // Read the header only (no full decode) so a small file that declares huge
            // dimensions can't be used to exhaust memory before we ever downscale it.
            $info = @getimagesize($absolutePath);
            if (! $info || $info[0] * $info[1] > 25_000_000) {
                return null;
            }

            $img = @imagecreatefromstring((string) @file_get_contents($absolutePath));
            if (! $img) {
                return null;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            // Downscale so we sample ~50px on the long edge — plenty for a dominant hue.
            $scale = max($w, $h) / 50;
            $tw = max(1, (int) round($w / $scale));
            $th = max(1, (int) round($h / $scale));
            $small = imagecreatetruecolor($tw, $th);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $tw, $th, $w, $h);

            // Bucket saturated pixels by hue (12 buckets), keep the most saturated
            // representative of the heaviest bucket.
            $weight = array_fill(0, 12, 0.0);
            $best = array_fill(0, 12, null); // [sat, r, g, b]

            for ($y = 0; $y < $th; $y++) {
                for ($x = 0; $x < $tw; $x++) {
                    $rgb = imagecolorat($small, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    [$hue, $sat, $val] = self::rgbToHsv($r, $g, $b);

                    // Skip near-white, near-black and washed-out/grey pixels.
                    if ($val < 0.15 || $sat < 0.30) {
                        continue;
                    }

                    $bucket = (int) floor($hue / 30) % 12;
                    // Weight by saturation so vivid pixels count more than muddy ones.
                    $weight[$bucket] += $sat;
                    if ($best[$bucket] === null || $sat > $best[$bucket][0]) {
                        $best[$bucket] = [$sat, $r, $g, $b];
                    }
                }
            }

            return [$weight, $best];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{0:float,1:float,2:float} hue 0-360, sat 0-1, val 0-1 */
    private static function rgbToHsv(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $d = $max - $min;

        $h = 0.0;
        if ($d > 0) {
            if ($max === $r) {
                $h = 60 * fmod((($g - $b) / $d), 6);
            } elseif ($max === $g) {
                $h = 60 * ((($b - $r) / $d) + 2);
            } else {
                $h = 60 * ((($r - $g) / $d) + 4);
            }
        }
        if ($h < 0) {
            $h += 360;
        }

        return [$h, $max > 0 ? $d / $max : 0.0, $max];
    }
}
