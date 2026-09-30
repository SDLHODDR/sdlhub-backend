<?php

use Endroid\QrCode\Builder\Builder;

class QRService
{
    public function generate($text, $fileName)
    {
        $result = Builder::create()
            ->data($text)
            ->size(300)
            ->build();

        $path = __DIR__ .
            '/../../../storage/qrcodes/' .
            $fileName . '.png';

        $result->saveToFile($path);

        return $path;
    }
}