<?php
require_once "lr_head.php";
include_once('numbertoword.php');

$currency_object = new Currency;

$data = json_decode(file_get_contents("php://input"), true);


try {
   // $page   = $data['page'] ?? 1;
   // $limit  = $data['limit'] ?? 10;
   // $status = $data['status'] ?? 'ALL';
   // $search = strtolower($data['search'] ?? '');

   // $offset = ($page - 1) * $limit;

   $first_date = date('d-M-Y', strtotime('first day of January last year'));
   $last_date  = date('d-M-Y', strtotime('last day of December last year'));

   $decodeStat = [
      'A' => 'Approved',
      'N' => 'Pending from Admin',
      'R' => 'Rejected',
      'T' => 'In Process'
   ];

   $result = [];
   $cnt = 1;

   /* =========================
      FETCH UNAPPROVED
   ========================= */
   $tourDetail_unapproved = multiRec("
   SELECT * FROM EPT_BCS_EMP_LEAVES_TEMP 
   WHERE EMP_CODE = '".$empCode."'
   AND STATUS IN ('T','R') 
   AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
   AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
   ORDER BY LVE_DATE_FR DESC, ID DESC
   ");

   if (!empty($tourDetail_unapproved)) {
   foreach ($tourDetail_unapproved as $tour) {

      $no_of_days_text = ($tour['TOTAL_DAYS'] == 0.5)
         ? 'Half Day'
         : $currency_object->get_number_to_text($tour['TOTAL_DAYS']);

      $result[] = [
         "ID" => $tour['ID'] ?? '',
         "LVE_CODE" => ucwords(trim($tour['LVE_CODE'] ?? '')),
         "NO_DAYS" => $no_of_days_text,
         "LVE_DATE_FR" => date('d-M-Y', strtotime($tour['LVE_DATE_FR'])),
         "LVE_DATE_TO" => date('d-M-Y', strtotime($tour['LVE_DATE_TO'])),
         "REMARKS" => $tour['REASON'] ?? '',
         "status" => $tour['STATUS'],
         "STATUS" => $decodeStat[$tour['STATUS']] ?? '',
         "statusColor" => $statusColorMap[$tour['STATUS']] ?? "secondary",
         "type" => "unapproved",
         "cnt" => $cnt++
      ];
   }
   }

   /* =========================
      FETCH APPROVED
   ========================= */
   $tourDetail_approved = multiRec("
   SELECT * FROM EPT_BCS_EMP_LEAVES
   WHERE EMP_CODE = '".$empCode."'
   AND STATUS IN('A', 'N')
   AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
   AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
   ORDER BY ID DESC, LVE_DATE_FR DESC
   ");

   if (!empty($tourDetail_approved)) {
   foreach ($tourDetail_approved as $tour) {

      $no_of_days_text = ($tour['NO_DAYS'] == 0.5)
         ? 'Half Day'
         : $currency_object->get_number_to_text($tour['NO_DAYS']);

      $result[] = [
         "ID" => $tour['ID'] ?? '',
         "LVE_CODE" => ucwords(trim($tour['LVE_CODE'] ?? '')),
         "NO_DAYS" => $no_of_days_text,
         "LVE_DATE_FR" => date('d-M-Y', strtotime($tour['LVE_DATE_FR'])),
         "LVE_DATE_TO" => date('d-M-Y', strtotime($tour['LVE_DATE_TO'])),
         "REMARKS" => $tour['REMARKS'] ?? '',
         "status" => $tour['STATUS'],
         "STATUS" => $decodeStat[$tour['STATUS']] ?? '',
         "statusColor" => $statusColorMap[$tour['STATUS']] ?? "secondary",
         "type" => "approved",
         "cnt" => $cnt++
      ];
   }
   }

   /* =========================
      TOTAL COUNT
   ========================= */
   $totalRecords = count($result);

   /* =========================
      PAGINATION (IMPORTANT)
   ========================= */
   //$paginatedData = array_slice($result, $offset, $limit);

   /* =========================
      RESPONSE
   ========================= */

   if($result || !empty($result)){
      apiResponse(
         true,
         "Leave Request fetched successfully.",
         [
            "data"   => $result,
            "total"  => $totalRecords
         ]
      );
   } else {
      apiResponse(false, "Unable to fetch leaves request  data.", null, 200);
   }
   
   exit;
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch leave request data.", null, 200);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}