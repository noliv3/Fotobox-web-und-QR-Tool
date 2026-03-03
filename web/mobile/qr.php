<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/bootstrap.php';

noCacheHeaders();
noIndexHeaders();

final class MiniQrPng
{
    public static function output(string $data): void
    {
        $size = 29;
        $scale = 8;
        $imgSize = $size * $scale;

        $im = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);

        $hash = hash('sha256', $data, true);
        $bits = '';
        for ($i = 0; $i < strlen($hash); $i++) {
            $bits .= str_pad(decbin(ord($hash[$i])), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $isFinder = ($x < 7 && $y < 7) || ($x > $size - 8 && $y < 7) || ($x < 7 && $y > $size - 8);
                if ($isFinder) {
                    $on = ($x % 6 === 0 || $y % 6 === 0 || ($x > 1 && $x < 5 && $y > 1 && $y < 5));
                } else {
                    $on = $bits[$bitIndex % strlen($bits)] === '1';
                    $bitIndex++;
                }

                if ($on) {
                    imagefilledrectangle($im, $x * $scale, $y * $scale, ($x + 1) * $scale - 1, ($y + 1) * $scale - 1, $black);
                }
            }
        }

        header('Content-Type: image/png');
        imagepng($im);
        imagedestroy($im);
    }
}

$data = trim((string) ($_GET['d'] ?? ''));
if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'missing_data';
    exit;
}

MiniQrPng::output($data);
