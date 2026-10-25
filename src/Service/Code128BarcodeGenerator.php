<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

class Code128BarcodeGenerator
{
    private const HEADER_IMAGE_PATH = __DIR__ . '/../../public_html/media/img/kod_image.png';
    private const HEADER_IMAGE_SPACING = 10;
    private const TEXT_FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const TEXT_FONT_SIZE = 22;
    private const FALLBACK_TEXT_SCALE = 2;
    private const BAR_TOP_PADDING = 10;

    /** @var array<int, string> */
    private const PATTERNS = [
        0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322', 5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
        10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231', 15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
        20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222', 25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
        30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123', 35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
        40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131', 45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
        50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123', 55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
        60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422', 65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
        70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211', 75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
        80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112', 85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
        90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141', 95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
        100 => '114131', 101 => '311141', 102 => '411131', 103 => '211412', 104 => '211214', 105 => '211232', 106 => '2331112',
    ];

    public function normalize(string $code): string
    {
        $normalized = trim($code);
        if ($normalized === '') {
            throw new InvalidArgumentException('Kod Code-128 nie może być pusty.');
        }

        if (preg_match('/[^\x20-\x7E]/', $normalized) === 1) {
            throw new InvalidArgumentException('Kod Code-128 może zawierać tylko znaki ASCII od 32 do 126.');
        }

        return $normalized;
    }

    public function generateJpeg(string $code, int $moduleWidth = 4, int $height = 180, bool $includeHeader = true): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Generowanie kodów Code-128 wymaga rozszerzenia PHP GD.');
        }

        $normalized = $this->normalize($code);
        $moduleWidth = max(1, min(8, $moduleWidth));
        $height = max(80, min(400, $height));
        $textAreaHeight = $this->getTextAreaHeight();
        $barHeight = $height - $textAreaHeight - self::BAR_TOP_PADDING;
        $quietModules = 10;
        $patterns = $this->encode($normalized);
        $moduleCount = array_sum(array_map(static fn(string $pattern): int => array_sum(array_map('intval', str_split($pattern))), $patterns));
        $width = ($moduleCount + ($quietModules * 2)) * $moduleWidth;

        $headerImage = $includeHeader ? $this->loadHeaderImage() : null;
        $headerWidth = $headerImage !== null ? min($width, imagesx($headerImage)) : 0;
        $headerHeight = $headerImage !== null ? (int) round(imagesy($headerImage) * ($headerWidth / imagesx($headerImage))) : 0;
        $headerSpacing = $headerHeight > 0 ? self::HEADER_IMAGE_SPACING : 0;
        $canvasHeight = $headerHeight + $headerSpacing + $height;

        $image = imagecreatetruecolor($width, $canvasHeight);
        if (!$image) {
            throw new RuntimeException('Nie udało się utworzyć obrazu kodu Code-128.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $width, $canvasHeight, $white);

        if ($headerImage !== null) {
            $headerLeft = (int) (($width - $headerWidth) / 2);
            if ($headerWidth === imagesx($headerImage) && $headerHeight === imagesy($headerImage)) {
                imagecopy($image, $headerImage, $headerLeft, 0, 0, 0, $headerWidth, $headerHeight);
            } else {
                imagecopyresampled($image, $headerImage, $headerLeft, 0, 0, 0, $headerWidth, $headerHeight, imagesx($headerImage), imagesy($headerImage));
            }
            imagedestroy($headerImage);
        }

        $x = $quietModules * $moduleWidth;
        $barcodeTop = $headerHeight + $headerSpacing;
        foreach ($patterns as $pattern) {
            $drawBar = true;
            foreach (str_split($pattern) as $widthUnits) {
                $segmentWidth = (int) $widthUnits * $moduleWidth;
                if ($drawBar) {
                    imagefilledrectangle($image, $x, $barcodeTop + self::BAR_TOP_PADDING, $x + $segmentWidth - 1, $barcodeTop + $barHeight, $black);
                }
                $x += $segmentWidth;
                $drawBar = !$drawBar;
            }
        }

        $this->drawReadableText($image, $normalized, 0, $width, $barcodeTop + $height - 10, $black);

        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        if (!is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Nie udało się wygenerować pliku JPG kodu Code-128.');
        }

        return $jpeg;
    }

    private function loadHeaderImage(): mixed
    {
        if (!is_file(self::HEADER_IMAGE_PATH)) {
            return null;
        }

        $image = imagecreatefrompng(self::HEADER_IMAGE_PATH);

        return $image ?: null;
    }

    private function getTextAreaHeight(): int
    {
        return function_exists('imagettfbbox') && is_file(self::TEXT_FONT_PATH) ? 36 : 34;
    }

    /** @return string[] */
    private function encode(string $code): array
    {
        $values = [104];
        foreach (str_split($code) as $char) {
            $values[] = ord($char) - 32;
        }

        $checksum = $values[0];
        for ($i = 1, $count = count($values); $i < $count; $i++) {
            $checksum += $values[$i] * $i;
        }
        $values[] = $checksum % 103;
        $values[] = 106;

        return array_map(static fn(int $value): string => self::PATTERNS[$value], $values);
    }

    private function drawReadableText(mixed $image, string $text, int $left, int $right, int $baseline, int $black): void
    {
        if (function_exists('imagettftext') && is_file(self::TEXT_FONT_PATH)) {
            $box = imagettfbbox(self::TEXT_FONT_SIZE, 0, self::TEXT_FONT_PATH, $text);
            $textWidth = $box === false ? 0 : $box[2] - $box[0];
            $x = (int) ($left + (($right - $left - $textWidth) / 2));
            imagettftext($image, self::TEXT_FONT_SIZE, 0, max(0, $x), $baseline, $black, self::TEXT_FONT_PATH, $text);
            return;
        }

        $font = 5;
        $scale = self::FALLBACK_TEXT_SCALE;
        $sourceWidth = strlen($text) * imagefontwidth($font);
        $sourceHeight = imagefontheight($font);
        $temp = imagecreatetruecolor(max(1, $sourceWidth), $sourceHeight);
        $white = imagecolorallocate($temp, 255, 255, 255);
        $tempBlack = imagecolorallocate($temp, 0, 0, 0);
        imagefilledrectangle($temp, 0, 0, $sourceWidth, $sourceHeight, $white);
        imagestring($temp, $font, 0, 0, $text, $tempBlack);
        $targetWidth = $sourceWidth * $scale;
        $targetHeight = $sourceHeight * $scale;
        $x = (int) ($left + (($right - $left - $targetWidth) / 2));
        imagecopyresampled($image, $temp, max(0, $x), $baseline - $targetHeight, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($temp);
    }
}
