<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../config/utils.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/emp_func.php";

header("Content-Type: application/json");

try {

    /* ===========================================
       SESSION VALIDATION
    =========================================== */

    $empCode = $_SESSION['emp_code'] ?? '';

    if (empty($empCode)) {
        apiResponse(
            false,
            "Unauthorized access.",
            null,
            401
        );
        exit;
    }


    /* ===========================================
       READ REQUEST DATA
    =========================================== */

    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    $companyId = trim(
        (string) (
            $input["company_id"]
            ?? $input["company"]
            ?? ""
        )
    );

    $divisionId = trim(
        (string) (
            $input["division_id"]
            ?? $input["division"]
            ?? ""
        )
    );

    $departmentId = trim(
        (string) (
            $input["department_id"]
            ?? $input["department"]
            ?? ""
        )
    );

    /* ===========================================
       COMPANY LIST
    =========================================== */

    $companyData = multiRec("
        SELECT
            COMP_ID,
            COMP_ID || ' - ' || COMP_DESC AS COMP_NAME
        FROM EPT_HR_COMPANY
        ORDER BY COMP_ID
    ");

    $companies = [];

    foreach ($companyData as $row) {

        $companies[] = [
            "value" => $row["COMP_ID"],
            "label" => $row["COMP_NAME"]
        ];
    }


    /* ===========================================
       DIVISION LIST
    =========================================== */

    $divisionData = multiRec("
        SELECT
            DIVSN_ID,
            DIVSN_ID || ' - ' || DIVSN_DESC AS DIVSN_NAME
        FROM EPT_HR_DIVISIONS
        ORDER BY DIVSN_DESC
    ");

    $divisions = [];

    foreach ($divisionData as $row) {

        $divisions[] = [
            "value" => $row["DIVSN_ID"],
            "label" => $row["DIVSN_NAME"]
        ];
    }


    /* ===========================================
       DEPARTMENT LIST
    =========================================== */

    $departmentData = multiRec("
        SELECT
            DEPT_ID,
            DEPT_ID || ' - ' || DEPT_DESC AS DEPT_NAME
        FROM EPT_HR_DEPARTMENT
        ORDER BY DEPT_DESC
    ");

    $departments = [];

    foreach ($departmentData as $row) {

        $departments[] = [
            "value" => $row["DEPT_ID"],
            "label" => $row["DEPT_NAME"]
        ];
    }


    /* ===========================================
       EMPLOYEE LIST
    =========================================== */

    $employees = [];


    /*
     * Company is required.
     *
     * Don't return all employees when no company
     * has been selected.
     */

    if (!empty($companyId)) {

        /* =========================================
           VALIDATE COMPANY
        ========================================= */

        if (!ctype_digit($companyId)) {

            apiResponse(
                false,
                "Invalid company selected.",
                null,
                400
            );

            exit;
        }


        /* =========================================
           VALIDATE DIVISION
        ========================================= */

        if (
            !empty($divisionId)
            && !ctype_digit($divisionId)
        ) {

            apiResponse(
                false,
                "Invalid division selected.",
                null,
                400
            );

            exit;
        }


        /* =========================================
           VALIDATE DEPARTMENT
        ========================================= */

        if (
            !empty($departmentId)
            && !ctype_digit($departmentId)
        ) {

            apiResponse(
                false,
                "Invalid department selected.",
                null,
                400
            );

            exit;
        }


        /* =========================================
           EMPLOYEE QUERY
        =========================================

           Employee master:
           EPT_BCS_EMPLOYEE

           Company:
           EPT_HR_EMPLOYEE_INFO.COMP_ID

           Division:
           EPT_HR_EMP_OFFICE_DET.DIVSN_ID

           Department:
           EPT_HR_EMP_OFFICE_DET.DEPT_ID

           Employee relationship:
           EMP_CODE
        */

        $employeeSql = "
            SELECT
                E.EMP_CODE,

                E.EMP_CODE ||
                ' - ' ||
                TRIM(
                    E.EMP_FNAME || ' ' || E.EMP_LNAME
                ) AS EMP_NAME

            FROM EPT_BCS_EMPLOYEE E

            WHERE E.STATUS = 'A'

              /* =================================
                 COMPANY FILTER
                 ================================= */

              AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_EMPLOYEE_INFO EI

                    WHERE EI.EMP_CODE = E.EMP_CODE
                      AND EI.COMP_ID = :company_id
              )
        ";


        /* =========================================
           DIVISION FILTER
        ========================================= 

        if (!empty($divisionId)) {

            $employeeSql .= "

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_EMP_OFFICE_DET EO

                    WHERE EO.EMP_CODE = E.EMP_CODE
                      AND EO.DIVSN_ID = :division_id
                )

            ";
        }*/


        /* =========================================
           DEPARTMENT FILTER
        ========================================= 

        if (!empty($departmentId)) {

            $employeeSql .= "

                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_EMP_OFFICE_DET EO

                    WHERE EO.EMP_CODE = E.EMP_CODE
                      AND EO.DEPT_ID = :department_id
                )

            ";
        }*/


        if (!empty($divisionId) || !empty($departmentId)) {

            $employeeSql .= "
                AND EXISTS (
                    SELECT 1
                    FROM EPT_HR_EMP_OFFICE_DET EO
                    WHERE EO.EMP_CODE = E.EMP_CODE
            ";

            if (!empty($divisionId)) {
                $employeeSql .= "
                    AND EO.DIVSN_ID = :division_id
                ";
            }

            if (!empty($departmentId)) {
                $employeeSql .= "
                    AND EO.DEPT_ID = :department_id
                ";
            }

            $employeeSql .= "
                )
            ";
        }


        /* =========================================
           ORDER
        ========================================= */

        $employeeSql .= "

            ORDER BY
                E.EMP_FNAME,
                E.EMP_LNAME

        ";


        /* =========================================
           PREPARE
        ========================================= */

        $employeeStmt = oci_parse(
            $sql___func___con,
            $employeeSql
        );

        if (!$employeeStmt) {

            $error = oci_error($sql___func___con);

            logOracleError(
                $error,
                $employeeSql
            );

            apiResponse(
                false,
                "Unable to prepare employee query.",
                null,
                500
            );

            exit;
        }


        /* =========================================
           BIND COMPANY
        ========================================= */

        oci_bind_by_name(
            $employeeStmt,
            ":company_id",
            $companyId
        );


        /* =========================================
           BIND DIVISION
        ========================================= */

        if (!empty($divisionId)) {

            oci_bind_by_name(
                $employeeStmt,
                ":division_id",
                $divisionId
            );
        }


        /* =========================================
           BIND DEPARTMENT
        ========================================= */

        if (!empty($departmentId)) {

            oci_bind_by_name(
                $employeeStmt,
                ":department_id",
                $departmentId
            );
        }


        /* =========================================
           EXECUTE
        ========================================= */

        if (!oci_execute($employeeStmt)) {

            $error = oci_error($employeeStmt);

            logOracleError(
                $error,
                $employeeSql
            );

            oci_free_statement($employeeStmt);

            apiResponse(
                false,
                "Unable to fetch employees.",
                null,
                500
            );

            exit;
        }


        /* =========================================
           BUILD EMPLOYEE LIST
        ========================================= */

        while ($row = oci_fetch_assoc($employeeStmt)) {

            $employees[] = [
                "value" => $row["EMP_CODE"],
                "label" => $row["EMP_NAME"]
            ];
        }


        oci_free_statement($employeeStmt);
    }


    /* ===========================================
       SUCCESS RESPONSE
    =========================================== */

    apiResponse(
        true,
        "Dropdown data fetched successfully.",
        [
            "companies"   => $companies,
            "divisions"   => $divisions,
            "departments" => $departments,
            "employees"   => $employees
        ]
    );

} catch (Throwable $e) {

    logOracleError(
        [
            "message" => $e->getMessage(),
            "file"    => $e->getFile(),
            "line"    => $e->getLine()
        ],
        "getEmployeeAccessDropdowns.php"
    );

    apiResponse(
        false,
        "Unable to fetch dropdown data.",
        null,
        500
    );

    exit;
}