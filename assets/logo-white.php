<?php
declare(strict_types=1);

ob_start();
include __DIR__ . '/logo-black.php';
$png = (string)ob_get_clean();

if (function_exists('imagecreatefromstring')) {
    $src = @imagecreatefromstring($png);
    if ($src !== false) {
        imagesavealpha($src, true);
        imagealphablending($src, false);
        $w = imagesx($src);
        $h = imagesy($src);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($src, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                imagesetpixel($src, $x, $y, imagecolorallocatealpha($src, 255, 255, 255, $alpha));
            }
        }
        header('Content-Type: image/png');
        header('Cache-Control: no-cache, must-revalidate');
        imagepng($src);
        imagedestroy($src);
        exit;
    }
}

header('Content-Type: image/png');
header('Cache-Control: no-cache, must-revalidate');
echo $png;
