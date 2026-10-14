<?php

//ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!defined('CURRENT_PORTAL')) {
    define('CURRENT_PORTAL', 'hrms');
}

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_hrms();

require_once __DIR__ . "/../../config/functions.php";
//require_once __DIR__ . "/../../config/env.php";
//require_once __DIR__ . "/../../config/emp_func.php";
//require_once __DIR__ . "/../../config/hr_func.php";
require_once __DIR__ . "/../../config/utils.php";

header("Content-Type: application/json; charset=UTF-8");

try {
    if (empty($_SESSION["emp_code"]) && empty($_SESSION["EmpCode"])) {
        apiResponse(false, "Session expired. Please login again.", null, 401);
    } else {
        $empCode = $_SESSION['emp_code'] ?? $_SESSION['EmpCode'] ?? '';
    }

    if ($_SERVER["REQUEST_METHOD"] !== "GET") {
        apiResponse(false, "Invalid request method.", null, 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (empty($data)) {
        $data = $_GET;
    }

    // print '<pre/>';
    // print_r($data);
    // exit;

    $empRecID = strtoupper(trim((string)($data["REC_ID"] ?? $data["EMP_CODE"] ?? "")));
    $taskId = trim((string)($data["TASK_ID"] ?? ""));
    $requestedTenureId = trim((string)($data["TENURE_CHANGE_ID"] ?? ""));

    if (!preg_match('/^[A-Z0-9_-]{1,30}$/', $empRecID)) {
        apiResponse(false, "A valid employee code is required.", null, 422);
    }

    if ($requestedTenureId !== "" && !ctype_digit($requestedTenureId)) {
        apiResponse(false, "Invalid tenure change ID.", null, 422);
    }

    $tenureSql = "
        SELECT * FROM (
            SELECT A.ID, A.EMP_CODE, A.ETYPE_ID, B.EMP_TYPE,
                   A.ETYPE_PERIOD, A.CURR_CTC, A.PROP_CTC,
                   DDMONYYYY(A.EFF_FROM) AS EFF_FROM,
                   DDMONYYYY(A.CTC_EFF_FROM) AS CTC_EFF_FROM,
                   A.REMARKS
            FROM HR_EMP_TENURE_CHANGE A
            LEFT JOIN HR_EMP_TYPE B ON B.ETYPE_ID = A.ETYPE_ID
            WHERE A.EMP_CODE = :EMP_CODE
              AND A.STATUS = 'T'
    ";
    $tenureBinds = [":EMP_CODE" => $empRecID];
    // if ($requestedTenureId !== "") {
    //     $tenureSql .= " AND A.ID = :TENURE_CHANGE_ID";
    //     $tenureBinds[":TENURE_CHANGE_ID"] = $requestedTenureId;
    // }
    $tenureSql .= " ORDER BY A.ID DESC ) WHERE ROWNUM = 1";
    $tenure = singRec($tenureSql, $tenureBinds);

    // print '<pre/';
    // print_r($tenure);
    // exit;

    // Authorization task IDs can differ from the tenure-change row ID; fall back to the employee's active tenure row.
    if (empty($tenure) && $requestedTenureId !== "") {
        $tenure = singRec(
            "SELECT * FROM (
                SELECT A.ID, A.EMP_CODE, A.ETYPE_ID, B.EMP_TYPE,
                       A.ETYPE_PERIOD, A.CURR_CTC, A.PROP_CTC,
                       DDMONYYYY(A.EFF_FROM) AS EFF_FROM,
                       DDMONYYYY(A.CTC_EFF_FROM) AS CTC_EFF_FROM,
                       A.REMARKS
                FROM HR_EMP_TENURE_CHANGE A
                LEFT JOIN HR_EMP_TYPE B ON B.ETYPE_ID = A.ETYPE_ID
                WHERE A.EMP_CODE = :EMP_CODE AND A.STATUS = 'T'
                ORDER BY A.ID DESC
            ) WHERE ROWNUM = 1",
            [":EMP_CODE" => $empRecID]
        );
    }

    // print '<pre/';
    // print '<br/>----------------------------------</br>';
    // print_r($tenure);
    //exit;

    if (empty($tenure)) {
        apiResponse(false, "No active tenure change was found for this employee.", null, 404);
    }
    
    $office = getEmpofficeHR($empRecID);
    
    if (empty($office["ORG_ID"])) {
        apiResponse(false, "Current employee office details could not be found.", null, 404);
    }

    // print '<br/>----------------------------------</br>';
    // print_r($office);
    //exit;
    $job = singRec(
        "SELECT JD_ID FROM HR_ORGANOGRAM WHERE ID = :ORG_ID",
        [":ORG_ID" => $office["ORG_ID"]]
    );
    if (empty($job["JD_ID"])) {
        apiResponse(false, "No job description is configured for this employee.", null, 404);
    }
    // print '<br/>----------------------------------</br>';
    // print_r($job);
    //exit;

    $savedPf = singRec(
        "SELECT MAX(AMOUNT_MNTH) AS AMOUNT_MNTH
         FROM HR_EMP_CTC_HEADS
         WHERE EMP_CODE = :EMP_CODE AND REF_ID = :REF_ID AND AD_CODE = 'XPFBS'",
        [":EMP_CODE" => $empRecID, ":REF_ID" => $tenure["ID"]]
    );
    $requestedPf = $data["XPFBS"] ?? null;
    if ($requestedPf !== null && !in_array((string)$requestedPf, ["0", "1"], true)) {
        apiResponse(false, "PF calculation input must be 0 or 1.", null, 422);
    }
    $pfInput = $requestedPf !== null
        ? (string)$requestedPf
        : (in_array((string)($savedPf["AMOUNT_MNTH"] ?? "0"), ["0", "1"], true)
            ? (string)($savedPf["AMOUNT_MNTH"] ?? "0")
            : "0");

    //print '<br/>----------------------------------</br>';
    // print_r($savedPf);
    // print_r($requestedPf);
    // print_r($pfInput);
    //exit;
    // The legacy structure helper reads this switch from the request.
    $data["XPFBS"] = $pfInput;
    //echo '==============';
    
    $currentStructure = get_ctc_structure_hr(
        $empRecID,
        (float)($tenure["CURR_CTC"] ?? 0),
        $tenure["ID"],
        $office["ORG_ID"]
    );
   
    $proposedStructure = get_ctc_structure_hr(
        $empRecID,
        (float)($tenure["PROP_CTC"] ?? 0),
        $tenure["ID"],
        $office["ORG_ID"]
    );

    //print '<br/>----------------------------------</br>';
    //print_r($currentStructure);
    //print_r($proposedStructure);
    //exit;

    $heads = multiRec(
        "SELECT A.AD_CODE, A.KEY AS HEAD_KEY, A.VAL AS HEAD_VAL,
                B.SH_DESCR AS DESCR, B.RND_OFF, B.RND_TO, B.SEQ_NO
         FROM HR_JD_CTC_HEADS A
         INNER JOIN HR_BCS_ALLW_DEDN B ON A.AD_ID = B.ID
         WHERE A.JD_ID = :JD_ID
           AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
         ORDER BY B.SEQ_NO",
        [":JD_ID" => $job["JD_ID"]]
    );

    $savedRows = multiRec(
        "SELECT AD_CODE, MAX(AMOUNT_MNTH) AS AMOUNT_MNTH,
                MAX(AMOUNT_YRLY) AS AMOUNT_YRLY
         FROM HR_EMP_CTC_HEADS
         WHERE EMP_CODE = :EMP_CODE AND REF_ID = :REF_ID
         GROUP BY AD_CODE",
        [":EMP_CODE" => $empRecID, ":REF_ID" => $tenure["ID"]]
    );
    $savedByCode = [];
    foreach ($savedRows as $savedRow) {
        $savedByCode[$savedRow["AD_CODE"]] = $savedRow;
    }

   // print_r($heads);
    // echo '---Tenure---';
    // echo $empRecID . "===" . $tenure['ID'];
    // print_r($tenure);
    // echo '---SaveRows---';
    // print_r($savedRows);
    // echo '---SavedByCode---';
    // print_r($savedByCode);
   // exit;
    $variableRows = multiRec(
        "SELECT A.AD_CODE, B.SH_DESCR AS DESCR,
                MAX(C.AMOUNT_MNTH) AS AMOUNT_MNTH
         FROM HR_JD_CTC_HEADS A
         INNER JOIN HR_BCS_ALLW_DEDN B ON A.AD_ID = B.ID
         LEFT JOIN HR_EMP_CTC_HEADS C
           ON C.EMP_CODE = :EMP_CODE AND C.AD_CODE = A.AD_CODE
         WHERE A.JD_ID = :JD_ID AND A.KEY = 'V'
           AND SYSDATE BETWEEN A.EFFEC_FROM AND NVL(A.EFFEC_TO, DATE '3000-03-01')
         GROUP BY A.AD_CODE, B.SH_DESCR, B.SEQ_NO
         ORDER BY B.SEQ_NO",
        [":EMP_CODE" => $empRecID, ":JD_ID" => $job["JD_ID"]]
    );

    $variableHeads = [];
    foreach ($variableRows as $variableRow) {
        $variableHeads[] = [
            "adCode" => $variableRow["AD_CODE"],
            "description" => $variableRow["DESCR"] ?? "",
            "monthly" => (float)($variableRow["AMOUNT_MNTH"] ?? 0),
            "editable" => (float)($tenure["PROP_CTC"] ?? 0) > 360000,
        ];
    }
    //print_r($variableRows);
    //print_r($variableHeads);
    //exit;

    $percentageLabels = [
        "F" => "Fixed",
        "C" => "% of CTC",
        "B" => "% of Basic",
        "V" => "Variable",
    ];
    $rows = [];
    $currentAnnualTotal = 0;
    $proposedMonthlyTotal = 0;
    $proposedAnnualTotal = 0;

    foreach ($heads as $head) {
        if (($head["HEAD_KEY"] ?? "") === "V") {
            continue;
        }

        $adCode = $head["AD_CODE"];
        $structureMonthly = (float)($proposedStructure[$adCode] ?? 0);
        $currentMonthly = (float)($currentStructure[$adCode] ?? 0);
        $saved = $savedByCode[$adCode] ?? [];
        $isReadonly = in_array($adCode, ["CBON", "CPF", "CGRA"], true);
        $savedMonthly = $saved["AMOUNT_MNTH"] ?? null;
        $savedAnnual = $saved["AMOUNT_YRLY"] ?? null;
        $monthly = ($savedMonthly === null || $savedMonthly === "")
            ? ($isReadonly ? $structureMonthly : null)
            : (float)$savedMonthly;
        $annual = ($savedAnnual === null || $savedAnnual === "")
            ? ($isReadonly ? $structureMonthly * 12 : null)
            : (float)$savedAnnual;
        $label = $percentageLabels[$head["HEAD_KEY"] ?? ""] ?? "";
        $description = $head["HEAD_KEY"] === "F"
            ? ($head["DESCR"] ?? "") . " (" . $label . ")"
            : ($head["DESCR"] ?? "") . " (" . ($head["HEAD_VAL"] ?? "") . " " . $label . ")";

        $rows[] = [
            "adCode" => $adCode,
            "description" => $description,
            "key" => $head["HEAD_KEY"] ?? "",
            "value" => $head["HEAD_VAL"] ?? "",
            "currentAnnual" => round($currentMonthly * 12, 2),
            "structureMonthly" => round($structureMonthly, 2),
            "structureAnnual" => round($structureMonthly * 12, 2),
            "monthly" => $monthly === null ? null : round($monthly, 2),
            "annual" => $annual === null ? null : round($annual, 2),
            "editable" => !$isReadonly,
        ];

        $currentAnnualTotal += $currentMonthly * 12;
        $proposedMonthlyTotal += $structureMonthly;
        $proposedAnnualTotal += $structureMonthly * 12;
    }

    //print_r($rows);
    //exit;

    $employeeName = getEmpInfoByCodeHRUtils($empRecID);
    // print '------------';
    // print_r($employeeName);
    // exit;
    $data = [
        "employee" => [
            "code" => $empRecID,
            "name" => $employeeName,
            "department" => $office["DEPT"] ?? "",
            "employeeType" => $tenure["EMP_TYPE"] ?? "",
            "period" => $tenure["ETYPE_PERIOD"] ?? "0",
            "location" => $office["LOC"] ?? "",
            "designation" => $office["DESIG"] ?? "",
            "currentCtc" => (float)($tenure["CURR_CTC"] ?? 0),
            "proposedCtc" => (float)($tenure["PROP_CTC"] ?? 0),
            "effectiveFrom" => $tenure["EFF_FROM"] ?? "",
            "ctcEffectiveFrom" => $tenure["CTC_EFF_FROM"] ?? "",
            "remarks" => $tenure["REMARKS"] ?? "",
        ],
        "tenureChangeId" => $tenure["ID"],
        "taskId" => $taskId,
        "pfInput" => $pfInput,
        "variableHeads" => $variableHeads,
        "rows" => $rows,
        "structureTotals" => [
            "currentAnnual" => round($currentAnnualTotal, 2),
            "proposedMonthly" => round($proposedMonthlyTotal, 2),
            "proposedAnnual" => round($proposedAnnualTotal, 2),
        ],
        "hasSavedHeads" => !empty($savedRows),
    ];

    // print_r($data);
    // exit;

    if (!empty($sql___func___con)) {
        oci_close($sql___func___con);
        $sql___func___con = null;
    }

    apiResponse(true, "Employee CTC heads fetched successfully.", $data, 200);
} catch (Throwable $e) {
    if (!empty($sql___func___con)) {
        @oci_rollback($sql___func___con);
        @oci_close($sql___func___con);
        $sql___func___con = null;
    }

    logOracleError([
        "message" => $e->getMessage(),
        "file" => $e->getFile(),
        "line" => $e->getLine(),
    ], "fetchEmpCTCHeads.php");

    apiResponse(false, "Unable to fetch employee CTC heads.", null, 500);
}
