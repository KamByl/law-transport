<?php

namespace App\Service;

use InvalidArgumentException;
use RuntimeException;

class Ean13BarcodeGenerator
{
    private const HEADER_IMAGE_PATH = __DIR__ . '/../../public_html/media/img/kod_image.png';
    private const HEADER_IMAGE_SPACING = 10;
    private const DIGIT_FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const DIGIT_FONT_SIZE = 28;
    private const FALLBACK_DIGIT_SCALE = 3;
    private const DIGIT_BASELINE_OFFSET = 10;
    private const BAR_TOP_PADDING = 8;

    private const LEFT_ODD = [
        '0' => '0001101',
        '1' => '0011001',
        '2' => '0010011',
        '3' => '0111101',
        '4' => '0100011',
        '5' => '0110001',
        '6' => '0101111',
        '7' => '0111011',
        '8' => '0110111',
        '9' => '0001011',
    ];

    private const LEFT_EVEN = [
        '0' => '0100111',
        '1' => '0110011',
        '2' => '0011011',
        '3' => '0100001',
        '4' => '0011101',
        '5' => '0111001',
        '6' => '0000101',
        '7' => '0010001',
        '8' => '0001001',
        '9' => '0010111',
    ];

    private const RIGHT = [
        '0' => '1110010',
        '1' => '1100110',
        '2' => '1101100',
        '3' => '1000010',
        '4' => '1011100',
        '5' => '1001110',
        '6' => '1010000',
        '7' => '1000100',
        '8' => '1001000',
        '9' => '1110100',
    ];

    private const PARITY = [
        '0' => 'OOOOOO',
        '1' => 'OOEOEE',
        '2' => 'OOEEOE',
        '3' => 'OOEEEO',
        '4' => 'OEOOEE',
        '5' => 'OEEOOE',
        '6' => 'OEEEOO',
        '7' => 'OEOEOE',
        '8' => 'OEOEEO',
        '9' => 'OEEOEO',
    ];

    public function normalize(string $code): string
    {
        $digits = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($digits) === 12) {
            return $digits . $this->calculateChecksum($digits);
        }

        if (strlen($digits) !== 13) {
            throw new InvalidArgumentException('Kod EAN musi zawierać 12 lub 13 cyfr.');
        }

        $expectedChecksum = $this->calculateChecksum(substr($digits, 0, 12));
        if ($digits[12] !== $expectedChecksum) {
            throw new InvalidArgumentException(sprintf(
                'Nieprawidłowa cyfra kontrolna kodu EAN. Oczekiwano %s.',
                $expectedChecksum
            ));
        }

        return $digits;
    }

    public function generateJpeg(string $code, int $moduleWidth = 6, int $height = 190, bool $includeHeader = true): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Generowanie kodów EAN wymaga rozszerzenia PHP GD.');
        }

        $ean = $this->normalize($code);
        $moduleWidth = max(1, min(8, $moduleWidth));
        $height = max(80, min(400, $height));
        $quietModules = 11;
        $digitAreaHeight = $this->getDigitAreaHeight();
        $barHeight = $height - $digitAreaHeight - self::BAR_TOP_PADDING;
        $width = (95 + ($quietModules * 2)) * $moduleWidth;
        $headerImage = $includeHeader ? $this->loadHeaderImage() : null;
        $headerWidth = $headerImage !== null ? min($width, imagesx($headerImage)) : 0;
        $headerHeight = $headerImage !== null ? (int) round(imagesy($headerImage) * ($headerWidth / imagesx($headerImage))) : 0;
        $headerSpacing = $headerHeight > 0 ? self::HEADER_IMAGE_SPACING : 0;
        $canvasHeight = $headerHeight + $headerSpacing + $height;

        $image = imagecreatetruecolor($width, $canvasHeight);
        if (!$image) {
            throw new RuntimeException('Nie udało się utworzyć obrazu kodu EAN.');
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

        $barcodeTop = $headerHeight + $headerSpacing;
        $bits = $this->encode($ean);
        $x = $quietModules * $moduleWidth;
        $bitCount = strlen($bits);

        for ($i = 0; $i < $bitCount; $i++) {
            if ($bits[$i] !== '1') {
                $x += $moduleWidth;
                continue;
            }

            $guard = $i < 3 || ($i >= 45 && $i < 50) || $i >= 92;
            imagefilledrectangle(
                $image,
                $x,
                $barcodeTop + self::BAR_TOP_PADDING,
                $x + $moduleWidth - 1,
                $barcodeTop + ($guard ? $barHeight + self::BAR_TOP_PADDING : $barHeight),
                $black
            );
            $x += $moduleWidth;
        }

        $this->drawReadableDigits($image, $ean, $barcodeTop, $height, $moduleWidth, $quietModules, $black);

        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        if (!is_string($jpeg) || $jpeg === '') {
            throw new RuntimeException('Nie udało się wygenerować pliku JPG kodu EAN.');
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

    private function getDigitAreaHeight(): int
    {
        return function_exists('imagettfbbox') && is_file(self::DIGIT_FONT_PATH) ? 42 : 50;
    }

    private function drawReadableDigits(mixed $image, string $ean, int $barcodeTop, int $height, int $moduleWidth, int $quietModules, int $black): void
    {
        if (!function_exists('imagettftext') || !is_file(self::DIGIT_FONT_PATH)) {
            $baseline = $barcodeTop + $height - 8;
            $barLeft = $quietModules * $moduleWidth;

            $this->drawCenteredScaledString($image, $ean[0], $barLeft - (7 * $moduleWidth), $barLeft - $moduleWidth, $baseline, $black);
            $this->drawCenteredScaledString($image, substr($ean, 1, 6), $barLeft + (3 * $moduleWidth), $barLeft + (45 * $moduleWidth), $baseline, $black);
            $this->drawCenteredScaledString($image, substr($ean, 7, 6), $barLeft + (50 * $moduleWidth), $barLeft + (92 * $moduleWidth), $baseline, $black);

            return;
        }

        $baseline = $barcodeTop + $height - self::DIGIT_BASELINE_OFFSET;
        $barLeft = $quietModules * $moduleWidth;

        $this->drawCenteredText($image, $ean[0], $barLeft - (7 * $moduleWidth), $barLeft - $moduleWidth, $baseline, $black);
        $this->drawCenteredText($image, substr($ean, 1, 6), $barLeft + (3 * $moduleWidth), $barLeft + (45 * $moduleWidth), $baseline, $black);
        $this->drawCenteredText($image, substr($ean, 7, 6), $barLeft + (50 * $moduleWidth), $barLeft + (92 * $moduleWidth), $baseline, $black);
    }

    private function drawCenteredText(mixed $image, string $text, int $left, int $right, int $baseline, int $black): void
    {
        $box = imagettfbbox(self::DIGIT_FONT_SIZE, 0, self::DIGIT_FONT_PATH, $text);
        $textWidth = $box === false ? 0 : $box[2] - $box[0];
        $x = (int) ($left + (($right - $left - $textWidth) / 2));
        imagettftext($image, self::DIGIT_FONT_SIZE, 0, max(0, $x), $baseline, $black, self::DIGIT_FONT_PATH, $text);
    }

    private function drawCenteredScaledString(mixed $image, string $text, int $left, int $right, int $baseline, int $black): void
    {
        $font = 5;
        $scale = self::FALLBACK_DIGIT_SCALE;
        $sourceWidth = strlen($text) * imagefontwidth($font);
        $sourceHeight = imagefontheight($font);
        $targetWidth = $sourceWidth * $scale;
        $targetHeight = $sourceHeight * $scale;
        $source = imagecreatetruecolor($sourceWidth, $sourceHeight);
        if (!$source) {
            return;
        }

        $white = imagecolorallocate($source, 255, 255, 255);
        $sourceBlack = imagecolorallocate($source, 0, 0, 0);
        imagefilledrectangle($source, 0, 0, $sourceWidth, $sourceHeight, $white);
        imagestring($source, $font, 0, 0, $text, $sourceBlack);

        $x = (int) ($left + (($right - $left - $targetWidth) / 2));
        imagecopyresized($image, $source, max(0, $x), $baseline - $targetHeight, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($source);
    }

    private function calculateChecksum(string $twelveDigits): string
    {
        if (!preg_match('/^\d{12}$/', $twelveDigits)) {
            throw new InvalidArgumentException('Do wyliczenia cyfry kontrolnej wymagane jest dokładnie 12 cyfr.');
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelveDigits[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    private function encode(string $ean): string
    {
        $bits = '101';
        $parity = self::PARITY[$ean[0]];

        for ($i = 1; $i <= 6; $i++) {
            $bits .= $parity[$i - 1] === 'O' ? self::LEFT_ODD[$ean[$i]] : self::LEFT_EVEN[$ean[$i]];
        }

        $bits .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $bits .= self::RIGHT[$ean[$i]];
        }

        return $bits . '101';
    }
}
