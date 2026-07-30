<?php
//  ini_set('display_errors', 1);
//  error_reporting(E_ALL);

require_once "gatepass/gp_head.php";

$calendarData = [];

/* =========================
   FETCH MAIN MENUS
========================= */
$colArr=array('S'=>'#1E90FF','O'=>'#FFAD5B','H'=>'#FFA0A0',  'W' => '#E0E0E0' );							
$textcolArr=array('S'=>'#1E90FF','O'=>'#003','H'=>'#003', 'W' => '#000');	

$sqlHoliday=multiRec("select 
    to_char(HOL_DATE,'yyyy-mm-dd') DAYDATE, 
    to_char(HOL_DATE,'mm') MONTH, 
    hol_type, 
    initcap(descr) descr
    from EPT_bcs_holidays 
    where hol_grp=(
        select hol_tblno 
        from EPT_bcs_employee 
        where emp_code='".$empCode."' )
        and HOL_DATE between to_date('01-Jan-".date('Y')."') and to_date('31-Dec-".date('Y')."') ");


foreach($sqlHoliday as $res)
{
	$calendarData[]=[
        'title'               => $res['DESCR'],
        'date'                => $res['DAYDATE'],
        'HOL_TYPE'            => $res['HOL_TYPE'], 
        'HOL_TYPE_COLOR'      => $colArr[$res['HOL_TYPE']],
        'HOL_TYPE_TEXT_COLOR' => $textcolArr[$res['HOL_TYPE']], 
        'flag'                => true
    ];
}

//print_records($calendarData);

echo json_encode([
    "status" => true,
    "menu"   => $calendarData
]);
