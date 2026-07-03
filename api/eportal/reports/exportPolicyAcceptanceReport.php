<?php

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

require_once __DIR__ . "/../../../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

try {

    if (!isset($_SESSION['emp_code'])) {
        die("Unauthorized Access");
    }

    $policyId = trim($_GET['policy_id'] ?? '');

    if (empty($policyId)) {
        die("Policy ID is required");
    }

    $con = db_eportal();

    /* =====================================================
       POLICY SUMMARY
    ====================================================== */

    $policySql = "
        SELECT
            POLI_ID,
            POLICY_NAME,
            IS_MANDAT,
            TO_CHAR(START_DATE,'DD-MON-YYYY') START_DATE,
            TO_CHAR(END_DATE,'DD-MON-YYYY') END_DATE
        FROM EPT_HR_POLICY
        WHERE POLI_ID = :policy_id
    ";

    $stmt = oci_parse($con, $policySql);
    oci_bind_by_name($stmt, ":policy_id", $policyId);
    oci_execute($stmt);

    $policy = oci_fetch_assoc($stmt);

    if (!$policy) {
        die("Policy not found");
    }

    /* =====================================================
       EMPLOYEE DETAILS
    ====================================================== */

    $detailSql = "
    SELECT
        E.EMP_CODE,

        TRIM(
            E.EMP_FNAME || ' ' ||
            NVL(E.EMP_MNAME,'') || ' ' ||
            E.EMP_LNAME
        ) EMP_NAME,

        DIVS.DIVSN_DESC DIVISION,
        DEPT.DEPT_DESC DEPARTMENT,

        HR_GET_DESIGN_NAME(E.DESIGNATION) DESIGNATION,

        CASE
            WHEN NVL(A.ACCEPTED_FLAG,'N')='Y'
            THEN 'Accepted'
            ELSE 'Pending'
        END POLICY_STATUS,

        TO_CHAR(
            A.ACCEPTED_ON,
            'DD-MON-YYYY HH24:MI:SS'
        ) ACCEPTED_ON,

        A.IP_ADDR,
        A.USER_AGENT

    FROM (
        SELECT *
        FROM (
            SELECT
                H.*,
                ROW_NUMBER() OVER(
                    PARTITION BY H.EMP_CODE
                    ORDER BY H.EMP_CODE
                ) RN
            FROM EPT_HR_EMP_OFFICE_DET H
        )
        WHERE RN = 1
    ) H

    JOIN EPT_BCS_EMPLOYEE E
        ON H.EMP_CODE = E.EMP_CODE

    LEFT JOIN EPT_USER_POLICY_VIEW_LOG A
        ON A.EMP_CODE = E.EMP_CODE
       AND A.POLICY_ID = :policy_id

    LEFT JOIN EPT_HR_DIVISIONS DIVS
        ON DIVS.DIVSN_ID = H.DIVSN_ID

    LEFT JOIN EPT_HR_DEPARTMENT DEPT
        ON DEPT.DEPT_ID = H.DEPT_ID

    WHERE E.STATUS='A'

    AND EXISTS (
        SELECT 1
        FROM EPT_HR_POLICY_DIVSN D
        WHERE D.POLICY_ID = :policy_id
        AND D.DIVSN_ID = H.DIVSN_ID
    )

    AND EXISTS (
        SELECT 1
        FROM EPT_HR_POLICY_DEPT DP
        WHERE DP.POLICY_ID = :policy_id
        AND DP.DEPT_ID = H.DEPT_ID
    )

    ORDER BY
        CASE
            WHEN NVL(A.ACCEPTED_FLAG,'N')='Y'
            THEN 1
            ELSE 2
        END,
        EMP_NAME
    ";

    $detailStmt = oci_parse($con, $detailSql);

    oci_bind_by_name(
        $detailStmt,
        ":policy_id",
        $policyId
    );

    oci_execute($detailStmt);

    $employees = [];

    $acceptedCount = 0;

    while ($row = oci_fetch_assoc($detailStmt)) {

        if ($row['POLICY_STATUS'] === 'Accepted') {
            $acceptedCount++;
        }

        $employees[] = $row;
    }

    $totalEmployees = count($employees);

    $pendingCount =
        $totalEmployees - $acceptedCount;

    $acceptancePercentage =
        $totalEmployees > 0
            ? round(
                ($acceptedCount / $totalEmployees) * 100,
                2
            )
            : 0;

    /* =====================================================
       EXCEL
    ====================================================== */

    $spreadsheet = new Spreadsheet();

    $sheet = $spreadsheet->getActiveSheet();

    $sheet->setTitle('Policy Acceptance');

    /* ===== TITLE ===== */

    $sheet->mergeCells('A1:H1');

    $sheet->setCellValue(
        'A1',
        'Policy Acceptance Report'
    );

    $sheet->getStyle('A1')->getFont()
        ->setBold(true)
        ->setSize(16);

    /* ===== SUMMARY ===== */

    $sheet->setCellValue('A3', 'Policy Name');
    $sheet->setCellValue('B3', $policy['POLICY_NAME']);

    $sheet->setCellValue('A4', 'Mandatory');
    $sheet->setCellValue(
        'B4',
        $policy['IS_MANDAT'] == 'Y'
            ? 'Yes'
            : 'No'
    );

    $sheet->setCellValue('A5', 'Start Date');
    $sheet->setCellValue(
        'B5',
        $policy['START_DATE']
    );

    $sheet->setCellValue('A6', 'End Date');
    $sheet->setCellValue(
        'B6',
        $policy['END_DATE']
    );

    $sheet->setCellValue(
        'D3',
        'Total Employees'
    );
    $sheet->setCellValue(
        'E3',
        $totalEmployees
    );

    $sheet->setCellValue(
        'D4',
        'Accepted'
    );
    $sheet->setCellValue(
        'E4',
        $acceptedCount
    );

    $sheet->setCellValue(
        'D5',
        'Pending'
    );
    $sheet->setCellValue(
        'E5',
        $pendingCount
    );

    $sheet->setCellValue(
        'D6',
        'Acceptance %'
    );
    $sheet->setCellValue(
        'E6',
        $acceptancePercentage . '%'
    );

    /* ===== HEADER ===== */

    $rowNo = 9;

    $headers = [
        'Employee Code',
        'Employee Name',
        'Designation',
        'Division',
        'Department',
        'Status',
        'Accepted On',
        'IP Address'
    ];

    $col = 'A';

    foreach ($headers as $header) {

        $sheet->setCellValue(
            $col . $rowNo,
            $header
        );

        $col++;
    }

    $sheet->getStyle(
        "A{$rowNo}:H{$rowNo}"
    )->applyFromArray([
        'font' => [
            'bold' => true
        ],
        'fill' => [
            'fillType' =>
                Fill::FILL_SOLID,
            'startColor' => [
                'rgb' => 'D9EAD3'
            ]
        ]
    ]);

    /* ===== DATA ===== */

    $rowNo++;

    foreach ($employees as $emp) {

        $sheet->setCellValue(
            'A' . $rowNo,
            $emp['EMP_CODE']
        );

        $sheet->setCellValue(
            'B' . $rowNo,
            $emp['EMP_NAME']
        );

        $sheet->setCellValue(
            'C' . $rowNo,
            $emp['DESIGNATION']
        );

        $sheet->setCellValue(
            'D' . $rowNo,
            $emp['DIVISION']
        );

        $sheet->setCellValue(
            'E' . $rowNo,
            $emp['DEPARTMENT']
        );

        $sheet->setCellValue(
            'F' . $rowNo,
            $emp['POLICY_STATUS']
        );

        $sheet->setCellValue(
            'G' . $rowNo,
            $emp['ACCEPTED_ON']
        );

        $sheet->setCellValue(
            'H' . $rowNo,
            $emp['IP_ADDR']
        );

        $rowNo++;
    }

    foreach (
        range('A', 'H')
        as $column
    ) {
        $sheet->getColumnDimension(
            $column
        )->setAutoSize(true);
    }

    $sheet->getStyle(
        "A9:H" . ($rowNo - 1)
    )->getBorders()->getAllBorders()
        ->setBorderStyle(
            Border::BORDER_THIN
        );

    /* =====================================================
       DOWNLOAD
    ====================================================== */

    $fileName =
        preg_replace(
            '/[^A-Za-z0-9_-]/',
            '_',
            $policy['POLICY_NAME']
        ) .
        '_Acceptance_Report.xlsx';

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="' .
        $fileName . '"'
    );

    header('Cache-Control: max-age=0');

    $writer = new Xlsx(
        $spreadsheet
    );

    $writer->save('php://output');

    exit;

} catch (Exception $e) {

    http_response_code(500);

    echo $e->getMessage();
}
