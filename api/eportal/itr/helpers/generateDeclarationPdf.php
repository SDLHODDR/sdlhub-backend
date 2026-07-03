<?php

require_once __DIR__ . "/../getEmployeeSummaryData.php";
require_once __DIR__ . "/../../../../vendor/autoload.php";

use Mpdf\Mpdf;

function generateDeclarationPdf(
    $empCode,
    $financialYear,
    $outputFile
)
{
    $response = getEmployeeSummaryData(
        $empCode,
        $financialYear
    );

    if (!$response['success']) {
        throw new Exception(
            $response['message']
        );
    }

    $summary = $response['data'];

    $templateFile =
        __DIR__ . "/../templates/declaration_template.php";

    if (!file_exists($templateFile)) {
        throw new Exception(
            "Declaration template not found"
        );
    }

    // Output directory
    $outputDir = dirname($outputFile);

    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0777, true)) {
            throw new Exception(
                "Unable to create output directory: " . $outputDir
            );
        }
    }

    if (!is_writable($outputDir)) {
        throw new Exception(
            "Output directory is not writable: " . $outputDir
        );
    }

    ob_start();
    include $templateFile;
    $html = ob_get_clean();

    if (trim($html) === '') {
        throw new Exception(
            "Declaration template generated empty HTML"
        );
    }

    // mPDF temp directory
    $tempDir = __DIR__ . '/../../../../../public/temp/mpdf';

    if (!is_dir($tempDir)) {
        if (!mkdir($tempDir, 0777, true)) {
            throw new Exception(
                "Unable to create mPDF temp directory: " . $tempDir
            );
        }
    }

    if (!is_writable($tempDir)) {
        throw new Exception(
            "mPDF temp directory is not writable: " . $tempDir
        );
    }

    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'margin_left' => 8,
        'margin_right' => 8,
        'margin_top' => 8,
        'margin_bottom' => 8,
        'tempDir' => $tempDir
    ]);

    $mpdf->WriteHTML($html);

    $mpdf->Output(
        $outputFile,
        \Mpdf\Output\Destination::FILE
    );

    return $outputFile;
}