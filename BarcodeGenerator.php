<?php
class BarcodeGenerator {
    private static $patterns = [
        '0' => 'nnnwwnwnn',
        '1' => 'wnnwnnnnw',
        '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw',
        'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn',
        'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw',
        'H' => 'wnnnnwwnn',
        'I' => 'nnwnnwwnn',
        'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww',
        'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn',
        'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww',
        'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn',
        'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw',
        'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn',
        'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw',
        '.' => 'wwnnnnwnn',
        ' ' => 'nwwnnnwnn',
        '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn',
        '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn',
        '*' => 'nwnnwnwnn',
    ];

    public static function generate($text, $height = 60, $scale = 2)
    {
        if (!extension_loaded('gd')) {
            throw new Exception('GD extension required');
        }

        $text = strtoupper($text);
        $encoded = '*' . $text . '*';

        $sequence = '';
        foreach (str_split($encoded) as $char) {
            if (!isset(self::$patterns[$char])) {
                throw new Exception("Invalid character in barcode: $char");
            }
            $sequence .= self::$patterns[$char] . 'n'; // narrow gap between chars
        }

        $narrow = $scale;
        $wide = $scale * 3;
        $width = 0;
        foreach (str_split($sequence) as $idx => $c) {
            $w = ($c === 'n') ? $narrow : $wide;
            $width += $w;
        }

        $img = imagecreatetruecolor($width, $height + 20);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefill($img, 0, 0, $white);

        $x = 0;
        $bar = true;
        foreach (str_split($sequence) as $c) {
            $w = ($c === 'n') ? $narrow : $wide;
            if ($bar) {
                imagefilledrectangle($img, $x, 10, $x + $w - 1, $height + 9, $black);
            }
            $x += $w;
            $bar = !$bar;
        }

        // Text below barcode
        $fontHeight = 10;
        $textX = ($width - imagefontwidth(2) * strlen($text)) / 2;
        imagestring($img, 2, $textX, $height + 10, $text, $black);

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return base64_encode($data);
    }
}
?>
