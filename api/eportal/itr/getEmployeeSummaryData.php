<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . "/../../config/session.php";
require_once __DIR__ . "/../../cors.php";
require_once __DIR__ . "/../../config/db.php";

$sql___func___con = db_eportal();

require_once __DIR__ . "/../../config/functions.php";
require_once __DIR__ . "/../../config/utils.php";

function getEmployeeSummaryData(
    $empCode,
    $financialYear = ''
)
{   

    try {

        if (empty($empCode)) {
            return [
                "success" => false,
                "message" => "Employee Code Missing"
            ];
        }

        $empCode = trim($empCode);

        /* =====================================================
        GET EMPLOYEE ID
        ===================================================== */

        $empId = singRec("
            SELECT ID
            FROM EPT_BCS_EMPLOYEE
            WHERE EMP_CODE = '{$empCode}'
        ")['ID'] ?? null;

        if (!$empId) {

            return [
                "success" => false,
                "message" => "Profile Not Found"
            ];

        }

        /* =====================================================
        CURRENT FY
        ===================================================== */

        $bcs_acct_period = singRec("
            SELECT *
            FROM EPT_BCS_ACCT_PERIOD
            WHERE SYSDATE BETWEEN FR_DATE AND TO_DATE 
        "); //CODE = '25-26'

        if (empty($bcs_acct_period)) {

            return [
                "success" => false,
                "message" => "Financial Year Not Found"
            ];
        }

        $fy      = $bcs_acct_period['CODE'] ?? '';
        $finYear = $bcs_acct_period['DESCR'] ?? '';

        if (!empty($financialYear)) {

            $selectedFy = singRec("
                SELECT *
                FROM EPT_BCS_ACCT_PERIOD
                WHERE DESCR = '{$financialYear}'
                OR CODE = '{$financialYear}'
            ");

            if (empty($selectedFy)) {
                return [
                    "success" => false,
                    "message" => "Invalid Financial Year"
                ];
            }

            $fy      = $selectedFy['CODE'];
            $finYear = $selectedFy['DESCR'];
        }

        $cmpArr = [
            '1' => 'SHREE DHOOTAPAPESHWAR LIMITED',
            '2' => 'SOLUMIKS HERBACEUTICALS LIMITED',
            '6' => 'Om Pharmaceuticals Limited'
        ];

        /* =====================================================
        EMPLOYEE DETAILS
        ===================================================== */

        $employee = singRec("
            SELECT
                HEI.EMP_CODE,
                HEI.FNAME,
                HEI.MNAME,
                HEI.LNAME,
                TO_CHAR(TO_DATE(HEI.DOB), 'dd-Mon-yyyy') AS DOB,
                HEI.GENDER,
                HBI.PAN_NO,
                HR_GET_DESIGN_NAME(HOD.DESI_ID) AS DESIG_NAME,
                HEI.COMP_ID AS COMPANY
            FROM EPT_HR_EMPLOYEE_INFO HEI

            INNER JOIN EPT_HR_EMP_OFFICE_DET HOD
                ON HOD.EMP_CODE = HEI.EMP_CODE

            INNER JOIN EPT_HR_EMP_BASIC_INFO HBI
                ON HBI.EMP_CODE = HEI.EMP_CODE

            WHERE HEI.EMP_CODE = '{$empCode}'
        ");

        if (empty($employee)) {
            return [
                "success" => false,
                "message" => "Employee Details Not Found"
            ];
        }

        /* =====================================================
        PTAX
        ===================================================== */

        $ptax = singRec("
            SELECT ABS(SUM(AMOUNT)) AS PTAX
            FROM EPT_BCS_ALLW_DEDN_ITAX
            WHERE FIN_YEAR = '{$finYear}'
            AND EMP_CODE = '{$empCode}'
            AND AD_CODE = 'PTAX'
        ");

        /* =====================================================
        REGIME
        ===================================================== */

        $emp_regime = singRec("
            SELECT *
            FROM EPT_BCS_ITAX_EMP_REGIME
            WHERE EMP_ID = '{$empId}'
            AND FY = '{$finYear}'
            ORDER BY ID DESC
            FETCH FIRST 1 ROWS ONLY
        ");

        $regime = $emp_regime['REGIME'] ?? 'N';

        $standardDeduction = ($regime === 'N') ? 75000 : 50000;

        $emp_name = trim(
            ($employee['FNAME'] ?? '') . ' ' .
            ($employee['MNAME'] ?? '') . ' ' .
            ($employee['LNAME'] ?? '')
        );

        /* =====================================================
        FETCH DEDUCTIONS SECTION WISE
        ===================================================== */

        $rows = multiRec("
            SELECT DISTINCT TRIM(SUB_SECTION) AS SUB_SECTION
            FROM EPT_BCS_ITAX_SETUP
            WHERE UPPER(TRIM(SUB_SECTION)) NOT IN (
                'OTHER INCOME',
                'OTHER SECTIONS'
            )
            ORDER BY 1
        ");

        $distinct_dedn = [];

        foreach ($rows as $row) {

            $distinct_dedn[] = $row['SUB_SECTION'];
        }

        $allDeductions = [];

        foreach ($distinct_dedn as $ddn) {

            $deductions = multiRec("
                SELECT
                    C.SUB_SECTION,
                    A.ITAX_ID,
                    A.ITAX_DESC,
                    C.LIMIT,
                    NVL(B.AMOUNT, 0) AS AMOUNT,
                    B.ATTACHMENTS

                FROM EPT_BCS_ITAX_HEADS A

                LEFT JOIN EPT_BCS_ITAX_DEDUCTIONS B
                    ON A.ITAX_ID = B.HEAD_ID
                    AND B.EMP_ID = '{$empId}'
                    AND B.FY = '{$finYear}'

                INNER JOIN EPT_BCS_ITAX_SETUP C
                    ON A.ITAX_ID = C.HEAD

                WHERE A.ITAX_ID IN (
                    SELECT HEAD
                    FROM EPT_BCS_ITAX_SETUP
                    WHERE SUB_SECTION = '{$ddn}'
                )

                ORDER BY A.ITAX_DESC
            ");

            $allDeductions[] = [
                "section_name" => $ddn,
                "records"      => $deductions ?: []
            ];
        }


        /*
        =====================================================
        OTHER INCOME - section 24
        =====================================================
        */

        $other_income = multiRec("
            SELECT
                a.itax_id,
                a.itax_desc,
                b.amount,
                b.agreement_attach
            FROM ept_bcs_itax_heads a
            LEFT JOIN ept_bcs_itax_other_income b
                ON a.itax_id = b.head_id
                AND b.emp_id = '{$empId}'
                AND b.fy = '{$finYear}'
            WHERE a.itax_id IN (
                SELECT head
                FROM ept_bcs_itax_setup
                WHERE sub_section = 'OTHER INCOME'
            )
            ORDER BY 1
        ");
        
        /*
        =====================================================
        OTHER INCOME TOTAL
        =====================================================
        */

        $total_other_income = 0;

        $formatted_other_income = [];

        foreach ($other_income as $row) {

            $headDesc = strtoupper(trim($row['ITAX_DESC'] ?? ''));

            $amount = (float)($row['AMOUNT'] ?? 0);

            if ($amount <= 0) {
                continue;
            }

            /*
            ============================================
            DEFAULT TYPE
            ============================================
            */

            $entryType = "INCOME";

            /*
            ============================================
            DEDUCTION HEADS
            ============================================
            */
            
            if (
                strpos($headDesc, 'HOUSING LOAN') !== false ||
                strpos($headDesc, 'MUNICIPAL') !== false
            ) {

                $entryType = "DEDUCTION";
                $total_other_income -= $amount;

            } else {

                /*
                ============================================
                POSITIVE INCOME
                ============================================
                */

                $total_other_income += $amount;
            }

            /*
            ============================================
            STORE FOR PREVIEW
            ============================================
            */

            $formatted_other_income[] = [
                "head_id"     => $row['ITAX_ID'] ?? '',
                "description" => $row['ITAX_DESC'] ?? '',
                "amount"      => $amount,
                "type"        => $entryType,
                "attachment"  => $row['AGREEMENT_ATTACH'] ?? ''
            ];
        }

        /* =====================================================
        GROUP DEDUCTIONS FOR PRINT PREVIEW
        ===================================================== */

        $groupedSections = [];

        foreach ($allDeductions as $section) {

            $sectionName = $section['section_name'] ?? '';
            $records     = $section['records'] ?? [];

            $filteredRecords = [];
            $sectionTotalEntered = 0;
            $sectionLimit = 0;

            foreach ($records as $record) {

                $amount = (float)($record['AMOUNT'] ?? 0);
                $limit  = (float)($record['LIMIT'] ?? 0);

                if ($amount <= 0) {
                    continue;
                }

                $sectionTotalEntered += $amount;

                if ($limit > $sectionLimit) {
                    $sectionLimit = $limit;
                }

                $filteredRecords[] = [
                    "head_id"        => $record['ITAX_ID'],
                    "description"    => $record['ITAX_DESC'],
                    "limit"          => $limit,
                    "entered_amount" => $amount,
                    "final_amount"   => $amount,
                    "attachment"     => $record['ATTACHMENTS'] ?? ''
                ];
            }

            $sectionTotal = $sectionTotalEntered;

            if ($sectionLimit > 0 && $sectionTotalEntered > $sectionLimit) {
                $sectionTotal = $sectionLimit;
            }

            if (!empty($filteredRecords)) {

                $groupedSections[] = [
                    "section_name" => $sectionName,
                    "total"        => $sectionTotal,
                    "records"      => $filteredRecords
                ];
            }
        }

        /* =====================================================
        EXEMPTIONS
        ===================================================== */

        $exemptions = multiRec("
            SELECT *
            FROM EPT_BCS_ITAX_EXEMPTION
            WHERE EMP_ID = '{$empId}'
            AND FY = '{$fy}'
            ORDER BY ID
        ");

        $empExemptions = [];

        foreach ($exemptions as $row) {

            $empExemptions[] = [
                "monthly_rent" => $row['MONTHLY_RENT'] ?? 0,
                "annual_rent"  => $row['ANNUAL_RENT'] ?? 0,
                "city"         => $row['CITY'] ?? '',
                "landlord_pan" => $row['LANDLORD_PAN'] ?? '',
                "from_month"   => $row['FROM_MONTH'] ?? '',
                "to_month"     => $row['TO_MONTH'] ?? ''
            ];
        }

        /* =====================================================
        GROSS SALARY
        ===================================================== */

        $total_allowance = singRec("
            SELECT NVL(SUM(AMOUNT),0) AS AMOUNT
            FROM EPT_BCS_ALLW_DEDN_ITAX
            WHERE FIN_YEAR = '{$finYear}'
            AND EMP_CODE = '{$empCode}'
            AND AMOUNT > 0
        ");

        $gross_salary = (float)($total_allowance['AMOUNT'] ?? 0);

        /* =====================================================
        RENT DATA
        ===================================================== */

        $total_rent_metro = singRec("
            SELECT NVL(SUM(ANNUAL_RENT),0) AS TRMETRO
            FROM EPT_BCS_ITAX_EXEMPTION
            WHERE EMP_ID = '{$empId}'
            AND FY = '{$fy}'
            AND CITY = 'Metro'
        ");

        $total_rent_nonmetro = singRec("
            SELECT NVL(SUM(ANNUAL_RENT),0) AS TRNONMETRO
            FROM EPT_BCS_ITAX_EXEMPTION
            WHERE EMP_ID = '{$empId}'
            AND FY = '{$fy}'
            AND CITY = 'Non Metro'
        ");

        $metroRent    = (float)($total_rent_metro['TRMETRO'] ?? 0);
        $nonMetroRent = (float)($total_rent_nonmetro['TRNONMETRO'] ?? 0);

        /* =====================================================
        ALLOWANCE MASTER
        ===================================================== */

        $res_allw_dedn = multiRec("
            SELECT *
            FROM EPT_BCS_ALLW_DEDN_ITAX
            WHERE EMP_CODE = '{$empCode}'
            AND FIN_YEAR = '{$finYear}'
        ");

        $emp_ad_arr = [];

        if (!empty($res_allw_dedn)) {

            foreach ($res_allw_dedn as $row) {

                $code   = $row['AD_CODE'] ?? '';
                $amount = (float)($row['AMOUNT'] ?? 0);

                if ($code != '') {
                    $emp_ad_arr[$code] = $amount;
                }
            }
        }

        $basicSalary = (float)($emp_ad_arr['BAS'] ?? 0);
        $hraAmount   = (float)($emp_ad_arr['HRA'] ?? 0);

        /* =====================================================
        HRA CALCULATION
        ===================================================== */

        $finm  = 0;
        $finnm = 0;

        if ($nonMetroRent > 0) {

            $rentMinus10BasicNM = $nonMetroRent - (0.10 * $basicSalary);

            if ($rentMinus10BasicNM < 0) {
                $rentMinus10BasicNM = 0;
            }

            $finnm = min(
                $hraAmount,
                $rentMinus10BasicNM,
                (0.40 * $basicSalary)
            );
        }

        if ($metroRent > 0) {

            $rentMinus10BasicM = $metroRent - (0.10 * $basicSalary);

            if ($rentMinus10BasicM < 0) {
                $rentMinus10BasicM = 0;
            }

            $finm = min(
                $hraAmount,
                $rentMinus10BasicM,
                (0.50 * $basicSalary)
            );
        }

        $finalhradeduction = (float)($finm + $finnm);

        /* =====================================================
        TOTAL DEDUCTIONS
        ===================================================== */

        $totalDeductions = 0;
        foreach ($groupedSections as $section) {
            $totalDeductions += (float)($section['total'] ?? 0);
        }

        /* =====================================================
            NET TAXABLE INCOME
        ===================================================== */
        $professionalTax = (float)($ptax['PTAX'] ?? 0);
        if ($regime == "O") {

            $net_taxable_income =
                $gross_salary
                + (float)$total_other_income
                - (float)$standardDeduction
                - (float)$finalhradeduction
                - (float)$professionalTax
                - (float)$totalDeductions;
        } else {
            $net_taxable_income =
                $gross_salary
                + (float)$total_other_income
                - (float)$standardDeduction;
        }
    
        if ($net_taxable_income < 0) {
            $net_taxable_income = 0;
        }

        /* =====================================================
        TAX CALCULATION
        ===================================================== */

        $slabs = multiRec("
            SELECT
                A.*
            FROM EPT_BCS_ITAX_SLABS A
            WHERE FY = '{$fy}'
            AND REGIME = '{$regime}'
            ORDER BY SLAB_START
        ");

        $net_taxable_income = (int)$net_taxable_income;

        $income_tax     = 0;  
        $rebate         = 0;
        $education_cess = 0;

        if (!empty($slabs)) {
        
            foreach ($slabs as $slab) {

                $min  = (float)($slab['SLAB_START'] ?? 0);
                $max  = (float)($slab['SLAB_END'] ?? 0);
                $rate = (float)($slab['PERC_TAX'] ?? 0) / 100;

                /*
                ============================================
                LAST SLAB CHECK
                ============================================
                */

                if ($max <= 0) {
                    if ($net_taxable_income > $min) {
                        $income_tax +=
                            ($net_taxable_income - $min) * $rate;
                    }

                    break;
                }

                /*
                ============================================
                SLAB RANGE
                ============================================
                */

                if ($net_taxable_income > $min) {
                    $taxableAmount = min($net_taxable_income, $max) - $min;
                    if ($taxableAmount > 0) {
                        $income_tax += ($taxableAmount * $rate);
                    }
                }
            }

            /* REBATE */
            if ($regime == "O") {

                // Old regime rebate u/s 87A
                if ($net_taxable_income <= 500000) {
                    $rebate = min($income_tax, 12500);
                }

            } else {

                // New regime rebate u/s 87A
                if ($net_taxable_income <= 1200000) {
                    $rebate = $income_tax;
                }
            }

            $final_income_tax = round($income_tax - $rebate);

            if ($final_income_tax < 0) {
                $final_income_tax = 0;
            }

            /* EDUCATION CESS */

            if ($final_income_tax > 0) {
                $education_cess = round($final_income_tax * 0.04);
            } else {
                $education_cess = 0;
            }

        } else {

            $income_tax = 0;
            $final_income_tax = 0;
            $education_cess = 0;
        }

        $total_tax_payable = round($final_income_tax + $education_cess);

        /* =====================================================
        RESPONSE
        ===================================================== */

        $response = [

                "employee_code"       => $employee['EMP_CODE'] ?? '',
                "employee_name"       => $emp_name,
                "employee_company"    => $cmpArr[$employee['COMPANY']] ?? "-",
                "designation"         => $employee['DESIG_NAME'] ?? '',
                "pan_no"              => $employee['PAN_NO'] ?? '',
                "dob"                 => $employee['DOB'] ?? '',
                "gender"              => ($employee['GENDER'] ?? '') == "1" ? "Male" : "Female",

                "gross_salary"        => round($gross_salary, 2),
                "professional_tax"    => round((float)($ptax['PTAX'] ?? 0), 2),

                "regime"              => $regime,
                "standard_deduction"  => round($standardDeduction, 2),
                "financial_year"      => $finYear,

                "deduction_sections"  => $groupedSections,
                "other_income"        => $formatted_other_income,
                "other_income_total"  => round($total_other_income, 2),
                "total_tax_payable"   => round($total_tax_payable, 2),

                "exemptions"          => $empExemptions,

                "hra_deduction"       => round($finalhradeduction, 2),
                "net_taxable_income"  => round($net_taxable_income, 2),
                "income_tax"          => round($final_income_tax, 2),
                "education_cess"      => round($education_cess, 2),

                "basic_salary"        => round($basicSalary, 2),
                "hra_amount"          => round($hraAmount, 2),
                "metro_rent"          => round($metroRent, 2),
                "non_metro_rent"      => round($nonMetroRent, 2)                
        ];  

        $response['generated_on'] = date('d-M-Y H:i:s');
        
        return [
            "success" => true,
            "data" => $response
        ];

    } catch (Exception $e) {

        return [
            "success" => false,
            "message" => $e->getMessage()
        ];
    }
}