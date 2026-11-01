<?php

namespace Tests\Unit;

use App\Support\ColorExtractor;
use PHPUnit\Framework\TestCase;

class ColorExtractorPrimaryTest extends TestCase
{
    public function test_saturated_primary_colours_survive_but_white_and_grey_do_not(): void
    {
        foreach ([[255, 0, 0, '#ff0000'], [0, 255, 0, '#00ff00'], [0, 0, 255, '#0000ff'], [255, 255, 255, null], [128, 128, 128, null]] as [$r, $g, $b, $expected]) {
            $path = tempnam(sys_get_temp_dir(), 'palette');
            try {
                $img = imagecreatetruecolor(20, 20);
                imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
                imagepng($img, $path);
                imagedestroy($img);
                $this->assertSame($expected, ColorExtractor::dominant($path));
            } finally {
                unlink($path);
            }
        }
    }
}
