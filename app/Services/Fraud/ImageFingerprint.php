<?php

namespace App\Services\Fraud;

/**
 * Perceptual image fingerprint (difference hash, 64 bits). Two pictures that
 * look the same — even after re-saving, resizing, recompressing or a light
 * crop — get fingerprints that differ in only a few bits, so a screenshot
 * reused by another account is found even when its bytes differ.
 */
class ImageFingerprint
{
    /** Bits that may differ for two images to count as the same picture. */
    public const SAME_IMAGE_DISTANCE = 6;

    /** 16-hex-char dHash, or null when the bytes aren't a readable image (or GD is missing). */
    public static function hash(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($binary);
        if (!$img) {
            return null;
        }

        // 9×8 greyscale: each row gives 8 left-vs-right comparisons.
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        imagedestroy($img);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $grey = (($rgb >> 16) & 0xFF) * 0.299 + (($rgb >> 8) & 0xFF) * 0.587 + ($rgb & 0xFF) * 0.114;
                if ($prev !== null) {
                    $bits .= $grey > $prev ? '1' : '0';
                }
                $prev = $grey;
            }
        }
        imagedestroy($small);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    /** Number of differing bits between two fingerprints (0–64). */
    public static function distance(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < 16; $i++) {
            $x = hexdec($a[$i] ?? '0') ^ hexdec($b[$i] ?? '0');
            $d += [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4][$x];
        }

        return $d;
    }
}
