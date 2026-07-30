<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json");

/* ===========================================
   DATABASE CONNECTION
=========================================== */
if (!$sql___func___con) {
    apiResponse(false, "Database connection failed.", null, 500);
}

/* ===========================================
   SESSION VALIDATION
=========================================== */

$empCode = $_SESSION['emp_code'] ?? '';
//$empCode = '00575';

if (empty($empCode)) {
    apiResponse(false, "Unauthorized access.", null, 401);
}

try {

    $currentDate = date("d-M-Y");

    /* ===========================================
       EARNINGS
    =========================================== */

    $earnings = multiRec("
        SELECT
            A.SEQ_NO,
            A.SH_DESCR,
            B.AMOUNT
        FROM BCS_EMPLOYEE_AD B
        INNER JOIN BCS_ALLW_DEDN A
            ON A.AD_CODE = B.AD_CODE
        WHERE B.EMP_CODE = '{$empCode}'
        AND '{$currentDate}' BETWEEN B.EFF_DATE AND B.EXP_DATE
        AND A.PROP_YN = 'Y'
        ORDER BY A.SEQ_NO
    ");

    $grossMonthly = 0;
    $grossYearly = 0;
    $earningsArr = [];

    foreach ($earnings as $row) {

        $monthly = (float)$row['AMOUNT'];
        $yearly = $monthly * 12;

        $grossMonthly += $monthly;
        $grossYearly += $yearly;

        $earningsArr[] = [
            "particular" => $row['SH_DESCR'],
            "monthly"    => $monthly,
            "yearly"     => $yearly
        ];
    }

    /* ===========================================
       BASIC SALARY (MBAS)
    =========================================== */

    $mbasRow = singRec("
        SELECT AMOUNT
        FROM BCS_EMPLOYEE_AD
        WHERE EMP_CODE = '{$empCode}'
        AND '{$currentDate}' BETWEEN EFF_DATE AND EXP_DATE
        AND AD_CODE = 'MBAS'
    ");

    $mbas = isset($mbasRow['AMOUNT'])
        ? (float)$mbasRow['AMOUNT']
        : 0;

    /* ===========================================
       CTC COMPONENTS
    =========================================== */

    $deductions = multiRec("
        SELECT
            AD.SEQ_NO,
            AD.DESCR,
            EAD.TYPE,
            EAD.AMOUNT
        FROM BCS_EMPLOYEE_AD EAD
        INNER JOIN BCS_ALLW_DEDN AD
            ON EAD.AD_CODE = AD.AD_CODE
        WHERE EAD.EMP_CODE = '{$empCode}'
        AND '{$currentDate}' BETWEEN EAD.EFF_DATE AND EAD.EXP_DATE
        AND EAD.AD_CODE IN ('CPF','CGRA','CBON','CESI')
        ORDER BY AD.SEQ_NO
    ");

    $ctcMonthly = 0;
    $ctcYearly = 0;
    $deductionsArr = [];

    foreach ($deductions as $row) {

        if ($row['TYPE'] === "P") {
            $monthly = round(($mbas * $row['AMOUNT']) / 100, 0);
        } else {
            $monthly = round(($row['AMOUNT'] * 12) / 100, 0);
        }

        $yearly = $monthly * 12;

        $ctcMonthly += $monthly;
        $ctcYearly += $yearly;

        $deductionsArr[] = [
            "particular" => $row['DESCR'],
            "monthly"    => $monthly,
            "yearly"     => $yearly
        ];
    }

    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Salary details fetched successfully.",
        [
            "earnings" => $earningsArr,
            "gross" => [
                "monthly" => $grossMonthly,
                "yearly"  => $grossYearly
            ],
            "deductions" => $deductionsArr,
            "ctc" => [
                "monthly" => $grossMonthly + $ctcMonthly,
                "yearly"  => $grossYearly + $ctcYearly
            ]
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage()
        ],
        "getSalaryStructure.php"
    );

    apiResponse(false, "Unable to fetch salary details.", null, 500);

} finally {

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
    }
}