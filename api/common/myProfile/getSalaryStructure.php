<?php
/*
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); */

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();
require_once __DIR__ . "/../../config/functions.php";

$empCode = $_SESSION['emp_code'] ?? '';

$empCode = '00575';

if (empty($empCode)) {    
    apiResponse(false,"Unauthorized access",null,401);
}

header('Content-Type: application/json');


$response = [
    "status" => false,
    "data" => null,
    "message" => ""
];

try {

    $currentDate = date('d-M-Y'); // dynamic instead of hardcoded

    // =========================
    // 1. EARNINGS (Gross)
    // =========================
    $earnings = multiRec("
        SELECT a.seq_no, a.SH_DESCR, b.amount
        FROM bcs_employee_ad b
        INNER JOIN bcs_allw_dedn a ON a.ad_code = b.ad_code
        WHERE b.emp_code = '".$empCode."'
        AND '".$currentDate."' BETWEEN b.eff_date AND b.exp_date
        AND a.prop_yn = 'Y'
        ORDER BY a.seq_no ASC
    ");

    $grossMonthly = 0;
    $grossYearly = 0;
    $earningsArr = [];

    foreach ($earnings as $row) {
        $monthly = (float)$row['AMOUNT'];
        $yearly  = $monthly * 12;

        $grossMonthly += $monthly;
        $grossYearly  += $yearly;

        $earningsArr[] = [
            "particular" => $row['SH_DESCR'],
            "monthly" => $monthly,
            "yearly" => $yearly
        ];
    }

    // =========================
    // 2. GET MBAS (Base Salary)
    // =========================
    $mbasRow = singRec("
        SELECT amount 
        FROM bcs_employee_ad
        WHERE emp_code = '".$empCode."'
        AND '".$currentDate."' BETWEEN eff_date AND exp_date
        AND ad_code = 'MBAS'
    ");

    $mbas = isset($mbasRow['AMOUNT']) ? (float)$mbasRow['AMOUNT'] : 0;

    // =========================
    // 3. DEDUCTIONS / CTC ADDITIONS
    // =========================
    $deductions = multiRec("
        SELECT ad.seq_no, ad.descr, ead.type, ead.amount
        FROM bcs_employee_ad ead
        INNER JOIN bcs_allw_dedn ad ON ead.ad_code = ad.ad_code
        WHERE ead.emp_code = '".$empCode."'
        AND '".$currentDate."' BETWEEN ead.eff_date AND ead.exp_date
        AND ead.ad_code IN ('CPF','CGRA','CBON','CESI')
        ORDER BY ad.seq_no
    ");

    $ctcMonthly = 0;
    $ctcYearly = 0;
    $deductionsArr = [];

    foreach ($deductions as $row) {

        if ($row['TYPE'] === 'P') {
            $monthly = round($mbas * $row['AMOUNT'] / 100, 0);
        } else {
            $monthly = round(($row['AMOUNT'] * 12) / 100, 0);
        }

        $yearly = $monthly * 12;

        $ctcMonthly += $monthly;
        $ctcYearly  += $yearly;

        $deductionsArr[] = [
            "particular" => $row['DESCR'],
            "monthly" => $monthly,
            "yearly" => $yearly
        ];
    }

    // =========================
    // FINAL RESPONSE
    // =========================
    $response['status'] = true;
    $response['data'] = [
        "earnings" => $earningsArr,
        "gross" => [
            "monthly" => $grossMonthly,
            "yearly" => $grossYearly
        ],
        "deductions" => $deductionsArr,
        "ctc" => [
            "monthly" => $grossMonthly + $ctcMonthly,
            "yearly" => $grossYearly + $ctcYearly
        ]
    ];

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
