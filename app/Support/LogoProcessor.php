<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

// Downscales an uploaded logo to at most 512px on the long edge (never upscales
// a small logo), preserving alpha transparency, and re-encodes as PNG — so a
// 5-20MB phone photo becomes a lean, predictable PNG before it ever touches disk
// or ColorExtractor. Mirrors ColorExtractor::bucketize()'s GD decode-guard
// pattern: read dimensions first (no full decode) to reject a decompression-bomb
// file, then imagecreatefromstring for all three accepted mimes at once.
class LogoProcessor
{
    private const MAX_DIMENSION = 512;

    /** @return string raw PNG bytes, resized + alpha-safe */
    public static function resize(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        $info = @getimagesize((string) $path);
        if (! $info || $info[0] * $info[1] > 25_000_000) {
            throw self::unreadable();
        }

        $img = @imagecreatefromstring((string) @file_get_contents((string) $path));
        if (! $img) {
            throw self::unreadable();
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, self::MAX_DIMENSION / max($w, $h)); // never upscale
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $canvas = imagecreatetruecolor($tw, $th);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $tw, $th, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($img);

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private static function unreadable(): ValidationException
    {
        return ValidationException::withMessages([
            'logo' => "That image couldn't be read — try a PNG or JPG.",
        ]);
    }
}
