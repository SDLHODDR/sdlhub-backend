<?php
// ini_set('display_errors', 1);
// error_reporting(E_ALL);

require_once "lr_head.php";
include_once('numbertoword.php');

$currency_object = new Currency;

try {
  $SITE_CODE      = $data['SITE_CODE'];
  $PRD_CODE_FROM  = $data['PRD_CODE_FROM'];
  $PRD_CODE_TO    = $data['PRD_CODE_TO'];
  $PROC_GRP_FROM  = $data['PROC_GRP_FROM'];
  $PROC_GRP_TO    = $data['PROC_GRP_TO'];
  $EMP_FROM       = $data['EMP_FROM'];
  $EMP_TO         = $data['EMP_TO'];

  $response = [];

  $sql = multiRecEPP("
    SELECT 
      d.dept_desc AS dept,
      TO_CHAR( TO_DATE(a.prd_code,'yyyymm'), 'Mon-YYYY' ) AS mon,
      a.prd_code,
      a.emp_code,
      b.emp_fname || ' ' || b.emp_lname AS emp_name,

      TO_CHAR( b.date_join, 'dd-Mon-yy' ) AS doj,

      a.paid_days,
      a.work_days,
      a.woff_days,
      a.holi_days,
      NVL(a.reg_cnt,0) reg_cnt,
      NVL(a.lves_taken,0) lves_taken,

      b.proc_group

        FROM bcs_attendance_mon a
        JOIN bcs_employee b
            ON a.emp_code = b.emp_code

        JOIN bcs_period c
            ON c.code = a.prd_code

        JOIN hrmslive.hr_department d
            ON b.dept_code = d.dept_code

        WHERE a.prd_code 
            BETWEEN '{$PRD_CODE_FROM}'
            AND '{$PRD_CODE_TO}'

        AND b.pay_site = '{$SITE_CODE}'

        AND b.proc_group 
            BETWEEN NVL('{$PROC_GRP_FROM}', b.proc_group)
            AND NVL('{$PROC_GRP_TO}', b.proc_group)

        AND b.emp_code 
            BETWEEN NVL('{$EMP_FROM}', b.emp_code)
            AND NVL('{$EMP_TO}', b.emp_code)

        AND b.date_join <= c.to_date

        ORDER BY 
            b.proc_group,
            d.dept_desc,
            b.emp_code,
            a.prd_code
    ");

    foreach ($sql as $res) {
      // =========================================
      // TOTAL DAYS IN MONTH
      // =========================================
      $totalDays = singRecEPP("
          SELECT TO_CHAR(
              LAST_DAY(
                  TO_DATE('{$res['PRD_CODE']}', 'yyyymm')
              ),
              'dd'
          ) total_days
          FROM dual
      ");
      // =========================================
      // PAID LEAVES
      // =========================================
      $empLv = multiRecEPP("
          SELECT 
              LVE_CODE,
              SUM(NO_DAYS) d

          FROM bcs_emp_leaves

          WHERE emp_code = '{$res['EMP_CODE']}'
          AND LVE_CODE <> 'LWP'

          AND lve_date_fr BETWEEN
              TO_DATE('{$res['PRD_CODE']}', 'yyyymm')
              AND LAST_DAY(
                  TO_DATE('{$res['PRD_CODE']}', 'yyyymm')
              )

          GROUP BY LVE_CODE
      ");

      $paidLeavesArr = [];

      foreach ($empLv as $lv) {
        $paidLeavesArr[] = $lv['LVE_CODE'] . ":" . ($lv['D'] + 0);
      }
      // =========================================
      // UNPAID LEAVES
      // =========================================
      $lwp = singRecEPP("
          SELECT NVL(SUM(NO_DAYS),0) LWP
          FROM bcs_emp_leaves

          WHERE emp_code = '{$res['EMP_CODE']}'
          AND LVE_CODE = 'LWP'

          AND lve_date_fr BETWEEN
              TO_DATE('{$res['PRD_CODE']}', 'yyyymm')
              AND LAST_DAY(
                  TO_DATE('{$res['PRD_CODE']}', 'yyyymm')
              )
      ");
      // =========================================
      // LEAVE BALANCE
      // =========================================
      $bal = multiRecEPP("
          SELECT (lve_code || ':' || bal_days) BAL
            FROM bcs_leave_balance
            WHERE emp_code = '{$res['EMP_CODE']}'
            AND TO_DATE( '{$res['PRD_CODE']}', 'yyyymm')
          BETWEEN eff_date AND upto_date
      ");

      $balance = singDymentionNew($bal);
      
      // =========================================
      // RESPONSE
      // =========================================
      $response[] = [
        "PRD_CODE"         => $res['PRD_CODE'],
        "MONTH"            => $res['MON'],
        "PROC_GROUP"       => $res['PROC_GROUP'],
        "DEPT"             => $res['DEPT'],
        "EMP_CODE"         => $res['EMP_CODE'],
        "EMP_NAME"         => ucwords( strtolower( $res['EMP_NAME'] ) ),
        "DOJ"              => $res['DOJ'],
        "PAID_DAYS"        => $res['PAID_DAYS'] + 0,
        "WORK_DAYS"        => $res['WORK_DAYS'] + 0,
        "WEEKLY_OFF"       => ($res['WOFF_DAYS'] + 0) + ($res['HOLI_DAYS'] + 0),
        "PAID_LEAVES"      => $res['LVES_TAKEN'] + 0,
        "PAID_LEAVE_DETAIL" => implode( ", ", $paidLeavesArr ),
        "UNPAID_LEAVES"    => $lwp['LWP'] + 0,
        "REGULARIZE"       => $res['REG_CNT'] + 0,
        "BALANCE"          => implode(", ", $balance)
      ];
    }

    //echo "<pre>"; print_r($response); echo "</pre>"; exit;

    echo json_encode([
      "status" => true,
      "data"   => $response
    ]);

} catch (Exception $e) {
  // echo json_encode([
  //   "success" => false,
  //   "message" => $e->getMessage()
  // ]);
  echo json_encode([
    "status" => false,
    "data"   => $e->getMessage()
  ]);
}

// $first_date = date('d-M-Y', strtotime('first day of January'));
// $last_date  = date('d-M-Y', strtotime('last day of December'));

// $decodeStat = [
//     'A' => 'Approved',
//     'N' => 'Pending from Admin',
//     'R' => 'Rejected',
//     'T' => 'In Process'
// ];

// $result = [];
// $cnt = 1;

// // /* =========================
// //    BUILD STATUS CONDITION
// // ========================= */
// $statusConditionMain = "AND STATUS IN ('A','N','R')";
// $statusConditionTemp = "AND STATUS IN ('A','N','R')";

// /* =========================
//    FETCH UNAPPROVED
// ========================= */
// $tourDetail_unapproved = multiRec("
//   SELECT * FROM epplive.BCS_EMP_LEAVES_TEMP 
//   WHERE EMP_CODE = '".$empCode."'
//   AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
//   AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
//   $statusConditionTemp
//   ORDER BY LVE_DATE_FR DESC, ID DESC
// ");

// if (!empty($tourDetail_unapproved)) {
//   foreach ($tourDetail_unapproved as $tour) {

//     $no_of_days_text = ($tour['TOTAL_DAYS'] == 0.5)
//         ? 'Half Day'
//         : $currency_object->get_number_to_text($tour['TOTAL_DAYS']);

//     $result[] = [
//         "ID" => $tour['ID'] ?? '',
//         "LVE_CODE" => ucwords(trim($tour['LVE_CODE'] ?? '')),
//         "NO_DAYS" => $no_of_days_text,
//         "LVE_DATE_FR" => date('d-M-Y', strtotime($tour['LVE_DATE_FR'])),
//         "LVE_DATE_TO" => date('d-M-Y', strtotime($tour['LVE_DATE_TO'])),
//         "REMARKS" => $tour['REASON'] ?? '',
//         "status" => $tour['STATUS'],
//         "STATUS" => $decodeStat[$tour['STATUS']] ?? '',
//         "statusColor" => $statusColorMap[$tour['STATUS']] ?? "secondary",
//         "type" => "unapproved",
//         "cnt" => $cnt++
//     ];
//   }
// }

// /* =========================
//    FETCH APPROVED
// ========================= */
// $tourDetail_approved = multiRec("
//   SELECT * FROM epplive.BCS_EMP_LEAVES
//   WHERE EMP_CODE = '".$empCode."'
//   AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
//   AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
//   $statusConditionMain
//   ORDER BY ID DESC, LVE_DATE_FR DESC
// ");

// if (!empty($tourDetail_approved)) {
//   foreach ($tourDetail_approved as $tour) {

//     $no_of_days_text = ($tour['NO_DAYS'] == 0.5)
//         ? 'Half Day'
//         : $currency_object->get_number_to_text($tour['NO_DAYS']);

//     $result[] = [
//         "ID" => $tour['ID'] ?? '',
//         "LVE_CODE" => ucwords(trim($tour['LVE_CODE'] ?? '')),
//         "NO_DAYS" => $no_of_days_text,
//         "LVE_DATE_FR" => date('d-M-Y', strtotime($tour['LVE_DATE_FR'])),
//         "LVE_DATE_TO" => date('d-M-Y', strtotime($tour['LVE_DATE_TO'])),
//         "REMARKS" => $tour['REMARKS'] ?? '',
//         "status" => $tour['STATUS'],
//         "STATUS" => $decodeStat[$tour['STATUS']] ?? '',
//         "statusColor" => $statusColorMap[$tour['STATUS']] ?? "secondary",
//         "type" => "approved",
//         "cnt" => $cnt++
//     ];
//   }
// }

// /* =========================
//    TOTAL COUNT
// ========================= */
// $totalRecords = count($result);

// /* =========================
//    RESPONSE
// ========================= */
// echo json_encode([
//     "status" => true,
//     "data"   => $result,
//     "total"  => $totalRecords
// ]);

exit;