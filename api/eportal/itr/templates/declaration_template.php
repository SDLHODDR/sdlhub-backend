<?php

if (!isset($summary) || empty($summary)) {
    die("Summary data not found");
}

if (!function_exists('formatAmount')) {
    function formatAmount($amount)
    {
        return number_format((float)($amount ?? 0), 2, ".", ",");
    }
}

$financialYear = $summary['financial_year'] ?? '';

$assessmentYear = '';

if (!empty($financialYear)) {
    $parts = explode('-', $financialYear);

    if (count($parts) === 2) {
        $assessmentYear =
            ((int)$parts[0] + 1)
            . '-'
            . ((int)$parts[1] + 1);
    }
}

$regimeText = 'Old Tax Regime';

if (($summary['regime'] ?? '') === 'N') {
    $regimeText = 'New Tax Regime';
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">

<style>

body{
    font-family: sans-serif;
    font-size:10px;
    color:#000;
}

table{
    width:100%;
    border-collapse:collapse;
}

th,
td{
    border:1px solid #000;
    padding:6px;
    vertical-align:top;
}

.header{
    text-align:center;
    font-weight:bold;
    background:#f1f1f1;
}

.section-header{
    background:#f1f1f1;
    font-weight:bold;
    text-decoration:underline;
}

.label{
    font-weight:bold;
    width:25%;
}

.center{
    text-align:center;
}

.right{
    text-align:right;
}

.total{
    font-weight:bold;
    background:#f8f8f8;
}

.note{
    line-height:1.6;
}

.page-break-avoid{
    page-break-inside:avoid;
}

</style>

</head>

<body>

<table>

    <!-- =====================================================
         HEADER
    ====================================================== -->

    <tr>
        <th colspan="4" class="header">
            <?= htmlspecialchars($summary['employee_company'] ?? '-') ?>
        </th>
    </tr>

    <tr>
        <th colspan="4" class="center">
            INVESTMENT DECLARATION FORM FOR THE FINANCIAL YEAR
            <?= htmlspecialchars($financialYear) ?>
        </th>
    </tr>

    <!-- =====================================================
         EMPLOYEE DETAILS
    ====================================================== -->

    <tr>
        <td class="label">Employee Code</td>
        <td><?= htmlspecialchars($summary['employee_code'] ?? '-') ?></td>

        <td class="label">PAN No</td>
        <td><?= htmlspecialchars($summary['pan_no'] ?? '-') ?></td>
    </tr>

    <tr>
        <td class="label">Employee Name</td>
        <td><?= htmlspecialchars($summary['employee_name'] ?? '-') ?></td>

        <td class="label">Designation</td>
        <td><?= htmlspecialchars($summary['designation'] ?? '-') ?></td>
    </tr>

    <tr>
        <td class="label">DOB</td>
        <td><?= htmlspecialchars($summary['dob'] ?? '-') ?></td>

        <td class="label">Gender</td>
        <td><?= htmlspecialchars($summary['gender'] ?? '-') ?></td>
    </tr>

    <tr>
        <td class="label">Assessment Year</td>
        <td><?= htmlspecialchars($assessmentYear) ?></td>

        <td class="label">Financial Year</td>
        <td><?= htmlspecialchars($financialYear) ?></td>
    </tr>

    <!-- =====================================================
         REGIME
    ====================================================== -->

    <tr>
        <td colspan="4">
            <b>
                Tax Scheme opted for Financial Year
                <?= htmlspecialchars($financialYear) ?>
            </b>
        </td>
    </tr>

    <tr>
        <td class="label">Regime</td>
        <td><?= $regimeText ?></td>
        <td></td>
        <td></td>
    </tr>

    <!-- =====================================================
         NOTE
    ====================================================== -->

    <tr>
        <td colspan="4" class="note">

            <b>Note:</b>

            All the tax reliefs and deductions provided under the Income Tax Act, 1961
            can be availed under the Old Tax Scheme only. Employee opting for Old Tax
            Scheme is required to fill the below Investment Declaration Form.

            <br><br>

            <b>
                I hereby declare that the following investment will be made by me during
                the financial year <?= htmlspecialchars($financialYear) ?>
                starting from 1st of April to 31st of March of
                <?= htmlspecialchars($financialYear) ?>
            </b>

        </td>
    </tr>

    <!-- =====================================================
         SALARY SUMMARY
    ====================================================== -->

    <tr>
        <th colspan="4" class="section-header">
            SALARY & TAX SUMMARY
        </th>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>GROSS SALARY</b>
        </td>
        <td class="right">
            <?= formatAmount($summary['gross_salary'] ?? 0) ?>
        </td>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>OTHER INCOME (SECTION 24)</b>
        </td>
        <td class="right">
            <?= formatAmount($summary['other_income_total'] ?? 0) ?>
        </td>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>STANDARD DEDUCTION</b>
        </td>
        <td class="right">
            <?= formatAmount($summary['standard_deduction'] ?? 0) ?>
        </td>
    </tr>

    <?php if (($summary['regime'] ?? '') === 'O') : ?>

        <tr>
            <td colspan="3" class="right">
                <b>PROFESSIONAL TAX</b>
            </td>
            <td class="right">
                <?= formatAmount($summary['professional_tax'] ?? 0) ?>
            </td>
        </tr>

        <tr>
            <td colspan="3" class="right">
                <b>HRA DEDUCTION</b>
            </td>
            <td class="right">
                <?= formatAmount($summary['hra_deduction'] ?? 0) ?>
            </td>
        </tr>

    <?php endif; ?>

    <!-- =====================================================
         DEDUCTION SECTIONS
    ====================================================== -->

    <?php if (
        ($summary['regime'] ?? '') === 'O'
        && !empty($summary['deduction_sections'])
    ) :
    ?>

        <?php foreach ($summary['deduction_sections'] as $section) : ?>

            <tr class="page-break-avoid">
                <th colspan="4" class="section-header">
                    <?= htmlspecialchars($section['section_name'] ?? '') ?>
                </th>
            </tr>

            <tr style="background:#f1f1f1;">
                <th width="10%">Sr No.</th>
                <th width="50%">Particulars</th>
                <th width="20%">Limit</th>
                <th width="20%">Amount</th>
            </tr>

            <?php foreach (($section['records'] ?? []) as $index => $row) : ?>

                <tr>

                    <td class="center">
                        <?= $index + 1 ?>
                    </td>

                    <td>
                        <?= htmlspecialchars($row['description'] ?? '') ?>
                    </td>

                    <td class="right">

                        <?php
                        $limit = $row['limit'] ?? 0;

                        echo $limit > 0
                            ? formatAmount($limit)
                            : '-';
                        ?>

                    </td>

                    <td class="right">
                        <?= formatAmount($row['final_amount'] ?? 0) ?>
                    </td>

                </tr>

            <?php endforeach; ?>

            <tr>

                <td colspan="3" class="right total">
                    TOTAL
                </td>

                <td class="right total">
                    <?= formatAmount($section['total'] ?? 0) ?>
                </td>

            </tr>

        <?php endforeach; ?>

    <?php endif; ?>

    <!-- =====================================================
         OTHER INCOME
    ====================================================== -->

    <?php if (!empty($summary['other_income'])) : ?>

        <tr>
            <th colspan="4" class="section-header">
                OTHER INCOME (SECTION 24)
            </th>
        </tr>

        <tr style="background:#f1f1f1;">
            <th>Sr No.</th>
            <th colspan="2">Particulars</th>
            <th>Amount</th>
        </tr>

        <?php foreach ($summary['other_income'] as $index => $income) : ?>

            <tr>

                <td class="center">
                    <?= $index + 1 ?>
                </td>

                <td colspan="2">
                    <?= htmlspecialchars($income['description'] ?? '') ?>
                </td>

                <td class="right">

                    <?php
                    $amount = $income['amount'] ?? 0;

                    if (($income['type'] ?? '') === 'DEDUCTION') {
                        echo '(-) ' . formatAmount($amount);
                    } else {
                        echo formatAmount($amount);
                    }
                    ?>

                </td>

            </tr>

        <?php endforeach; ?>

        <tr>

            <td colspan="3" class="right total">
                TOTAL OTHER INCOME
            </td>

            <td class="right total">
                <?= formatAmount($summary['other_income_total'] ?? 0) ?>
            </td>

        </tr>

    <?php endif; ?>

    <!-- =====================================================
         TAX SUMMARY
    ====================================================== -->

    <tr>
        <td colspan="3" class="right">
            <b>NET TAXABLE INCOME</b>
        </td>

        <td class="right">
            <?= formatAmount($summary['net_taxable_income'] ?? 0) ?>
        </td>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>INCOME TAX</b>
        </td>

        <td class="right">
            <?= formatAmount($summary['income_tax'] ?? 0) ?>
        </td>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>EDUCATION CESS</b>
        </td>

        <td class="right">
            <?= formatAmount($summary['education_cess'] ?? 0) ?>
        </td>
    </tr>

    <tr>
        <td colspan="3" class="right">
            <b>TOTAL TAX PAYABLE</b>
        </td>

        <td class="right">
            <?= formatAmount($summary['total_tax_payable'] ?? 0) ?>
        </td>
    </tr>

    <!-- =====================================================
         DECLARATION
    ====================================================== -->

    <tr>

        <td colspan="4" style="line-height:1.8;">

            <b>Declaration:</b>

            <br><br>

            I
            <b>
                <?= htmlspecialchars($summary['employee_name'] ?? '') ?>
            </b>

            hereby declare that the information given above is correct and true in all respects.

            <br>

            I also undertake to indemnify the company for any loss/liability that may arise in the event of the above information being incorrect.

            <br><br>

            Date :
            <?= date('d/m/Y') ?>

            <br><br>

            Place :

            <br><br><br>

            <div style="text-align:right;">
                <b>Employee Signature</b>
            </div>

        </td>

    </tr>

</table>

</body>
</html>

