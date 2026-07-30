<?php
require_once "lr_head.php";
include_once('numbertoword.php');

$currency_object = new Currency;

$data = json_decode(file_get_contents("php://input"), true);

$page   = $data['page'] ?? 1;
$limit  = $data['limit'] ?? 10;
$status = $data['status'] ?? 'ALL';
$search = strtolower($data['search'] ?? '');

$offset = ($page - 1) * $limit;

$first_date = date('d-M-Y', strtotime('first day of January'));
$last_date  = date('d-M-Y', strtotime('last day of December'));

$decodeStat = [
    'A' => 'Approved',
    'N' => 'Pending from Admin',
    'R' => 'Rejected',
    'T' => 'In Process'
];

$result = [];
$cnt = 1;

/* =========================
   BUILD STATUS CONDITION
========================= */
$statusConditionTemp = "AND STATUS IN ('T','R')";
$statusConditionMain = "AND STATUS IN ('A','N')";

if ($status !== 'ALL') {
    if (in_array($status, ['T','R'])) {
        $statusConditionTemp = "AND STATUS = '".$status."'";
        $statusConditionMain = "AND 1=0"; // skip main table
    } else {
        $statusConditionTemp = "AND 1=0"; // skip temp
        $statusConditionMain = "AND STATUS = '".$status."'";
    }
}

/* =========================
   SEARCH CONDITION
========================= */
$searchCondition = "";
if (!empty($search)) {
    $searchCondition = " AND (
        LOWER(LVE_CODE) LIKE '%".$search."%' OR
        LOWER(REMARKS) LIKE '%".$search."%'
    )";
}

/* =========================
   FETCH UNAPPROVED
========================= */
$tourDetail_unapproved = multiRec("
  SELECT * FROM EPT_BCS_EMP_LEAVES_TEMP 
  WHERE EMP_CODE = '".$empCode."'
  AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
  AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
  $statusConditionTemp
  $searchCondition
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
  AND LVE_DATE_FR >= TO_DATE('".$first_date."','dd-Mon-yy')
  AND LVE_DATE_TO <= TO_DATE('".$last_date."','dd-Mon-yy')
  $statusConditionMain
  $searchCondition
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
$paginatedData = array_slice($result, $offset, $limit);

/* =========================
   RESPONSE
========================= */
echo json_encode([
    "status" => true,
    "data"   => $paginatedData,
    "total"  => $totalRecords
]);
exit;