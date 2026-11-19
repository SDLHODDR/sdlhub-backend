<?php
/*
 * Appointment letter - Field (ported from legacy reports/pdfReport/appointment_letter_field.php).
 * Setup, access check and saving: appointment_letter_common.php.
 */
require_once __DIR__ . '/appointment_letter_common.php';

$pdf = appointmentNewPdf(1.1);

$currentDate = date('d-M-Y');

$OFFER_INFO = singRec("select a.* , get_comp_name(a.COMP_ID)CMPNM , b.retire_age from hr_employee_info a 
						inner join hr_emp_basic_info b on a.emp_code=b.emp_code where a.emp_code='" . $empCode . "'");

$EMP_PERIOD = singRec("select * from hr_emp_tenure where emp_code ='" . $OFFER_INFO['EMP_CODE'] . "'");

$JOINING_INFO = singRec("select * from hr_emp_tenure a where a.emp_code='" . $OFFER_INFO['EMP_CODE'] . "' AND {$asOnSql} BETWEEN EFF_FROM AND nvl(EFF_TO,'31-Mar-3000')");

$DOC_INFO = singRec("select * from (select * from hr_emp_docs where emp_code ='" . $OFFER_INFO['EMP_CODE'] . "' and doc_id = '2' order by id desc) where rownum=1");
$docExt = singRec("select * from (select * from hr_emp_docs where emp_code ='" . $OFFER_INFO['EMP_CODE'] . "' and doc_id = '2' order by id desc) where rownum=1");

$STATE = singRec("select GSTAT_DESC from epplive.bcs_gst_states a where a.gstat_code='" . $OFFER_INFO['STATE'] . "'");

$COMPANY = singRec("select * from hr_company where comp_id ='" . $OFFER_INFO['COMP_ID'] . "'"); //arr($COMPANY);exit;

$empres = singRec("select desi_id, get_design_name(desi_id)ds  , dept_id , divsn_id, GET_DIVISION_NAME(divsn_id)DVSN , org_id from hr_emp_office_det where emp_code='".$OFFER_INFO['EMP_CODE']."' and {$asOnSql} between effec_from and nvl(effec_to , '03-Mar-3000')");

$empoff = appointmentEmpOffice($OFFER_INFO['EMP_CODE']) ;
$bossoff = appointmentEmpOffice(findParentOrgEmp($OFFER_INFO['EMP_CODE']));

$signatories = singRec("SELECT DOCTYP_ID, DOCTYP_DESC , ORG_LOC_ID FROM HR_DIVSN_DOC hjd RIGHT JOIN hr_doc_types hdt on 
                        hjd.doc_id = hdt.DOCTYP_ID AND DOC_TYPE ='D' 
                        and COMP_ID='" . $OFFER_INFO['COMP_ID'] . "' 
                        and DIVSN_ID='" . $empres['DIVSN_ID'] . "'
						and DEPT_ID='" . $empres['DEPT_ID'] . "'  
                        and DESI_ID='" . $empres['DESI_ID'] . "'
                        WHERE DOC_TYPE ='D' and DOCTYP_ID='2' ");

$appoint_sign = singRec("select c.TITLE_DESC , a.EMP_CODE , get_design_name(a.desi_id)desig, get_dept_name(a.dept_id) dept  
						from hr_emp_office_det a 
                        inner join hr_employee_info b on a.emp_code=b.emp_code 
                        inner join hr_titles c on c.title_id = b.title	
                        where org_loc_id='" . $signatories['ORG_LOC_ID'] . "'");

$Org_Info = singRec("select EMP_LEVEL , JD_ID from HR_ORGANOGRAM where ID ='" .$empoff['ORG_ID']. "'"); /*arr($JD_RESP);exit;*/

$sqlalloworg = multiRec("select distinct hol.id ,hjd.allow_id , ha.allow_desc , hjd.allow_amount , hjd.add_info 
from hr_org_loc_allowances hol
inner join hr_allowances ha on hol.allow_id = ha.allow_id 
inner join hr_organogram org on org.id = hol.org_id 
inner join hr_jd_allowances hjd on org.jd_id = hjd.jd_id and hjd.allow_id = hol.allow_id
where hol.org_loc_id='".$empoff['ORG_LOC_ID']."'
and {$asOnSql} BETWEEN hol.effec_from and nvl(hol.effec_to , '01-Mar-3000')
and hjd.exp_type='A' order by 1
");

$sqlreiorg = multiRec("select distinct hol.id, hjd.allow_id , ha.allow_desc , hjd.allow_amount , hjd.add_info 
from hr_org_loc_allowances hol
inner join hr_allowances ha on hol.allow_id = ha.allow_id 
inner join hr_organogram org on org.id = hol.org_id 
inner join hr_jd_allowances hjd on org.jd_id = hjd.jd_id and hjd.allow_id = hol.allow_id
where hol.org_loc_id='".$empoff['ORG_LOC_ID']."'
and {$asOnSql} BETWEEN hol.effec_from and nvl(hol.effec_to , '01-Mar-3000')
and hjd.exp_type='R' order by 1
");

// Legacy referenced $sqlallow / $sqlrei (never defined, so no allowances
// were listed); use the organogram location lists, as the HO letter does.
$sqlallow = array_map(function ($row) { $row['ADESC'] = $row['ALLOW_DESC']; return $row; }, $sqlalloworg ?: []);
$sqlrei = array_map(function ($row) { $row['ADESC'] = $row['ALLOW_DESC']; return $row; }, $sqlreiorg ?: []);

$JD_RESP = singRec("select DESCR from hr_jd_description where jd_id ='".$Org_Info['JD_ID']."'"); /*arr($JD_RESP);exit;*/

$locnm = array('100'=> 'Head Quarter' , '90' => 'District' , '80' => 'Region' , '70' => 'Zone' , '60' => 'India');
// echo $locnm[$Org_Info['EMP_LEVEL']];
// $locnm = get_descr_table('LEVL_DESC' , 'HR_SFM_NEW_EMP_LEVELS' , 'LEVL' , $Org_Info['EMP_LEVEL']);
// echo $locnm;
// exit;
// if ($Org_Info['LOC_TYPE'] == 'C') {
// 	$locnm = "Country";
// } else if ($Org_Info['LOC_TYPE'] == 'T') {
// 	$locnm = "Top Zone";
// } else if ($Org_Info['LOC_TYPE'] == 'Z') {
// 	$locnm = "Zone";
// } else if ($Org_Info['LOC_TYPE'] == 'R') {
// 	$locnm = "Region";
// } else if ($Org_Info['LOC_TYPE'] == 'H') {
// 	$locnm = "Head Quarter";
// } else if ($Org_Info['LOC_TYPE'] == 'D') {
// 	$locnm = "District";
// }

$session_empCode = singRec("select work_site, dept_code from epplive.bcs_employee where emp_code='" . $_SESSION['EmpCode'] . "'"); //eppSiteCode

$finData = singRec("select to_char(fr_date,'dd-Mon-rrrr') frdt,
	to_char(to_date,'dd-Mon-rrrr') trdt,
	to_char(fr_date,'rrrr-mm-dd') calfrdt,
	to_char(to_date,'rrrr-mm-dd') caltodt, doc_prefix
	from epplive.bcs_acct_period
	where code = (select acct_prd from epplive.bcs_period where trunc(sysdate) between fr_date and to_date)"); //DOC_PREFIX

$CTC_table = multiRec("select a.emp_code, a.ad_code, b.seq_no, b.sh_descr, a.type , a.amount, b.cost_company
	from  epplive.bcs_employee_ad a , epplive.bcs_allw_dedn b 
	where a.emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and b.cost_company = 'Y'
	and a.ad_code = b.ad_code
	and {$asOnSql} between a.eff_date and a.exp_date 
	order by b.seq_no"); //CTC 
//arr($CTC_table);exit;
$file_path = hrmsDocPath(EMP_DOCS_DIR . '/Appointment_Letter_' . $OFFER_INFO['ID'] . '.pdf');
// Reference: created by joining/generateAppointmentLetterReference.php.
$refNo = '';
	$mbas = singRec("select ID, AMOUNT, TYPE from epplive.BCS_EMPLOYEE_AD where {$asOnSql} between EFF_DATE and EXP_DATE
		and emp_code='" . $OFFER_INFO['EMP_CODE'] . "' and CONFIRMED='Y' and AD_CODE='MBAS' ");
	$mctc = singRec("select ID, AMOUNT, TYPE from epplive.BCS_EMPLOYEE_AD where {$asOnSql} between EFF_DATE and EXP_DATE
		and emp_code='" . $OFFER_INFO['EMP_CODE'] . "' and CONFIRMED='Y' and AD_CODE='MCTC' ");
	$SAL_TOTAL = singRec("select sum(a.amount) amt from  epplive.bcs_employee_ad a , epplive.bcs_allw_dedn b 
					where a.emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and b.cost_company = 'Y'
					and a.ad_code = b.ad_code and {$asOnSql} between a.eff_date and a.exp_date");
	// arr ($SAL_TOTAL); echo '<br>';
	$pfbas = singRec("select amount from epplive.bcs_employee_ad where emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and {$asOnSql} between eff_date and exp_date and ad_code = 'XPFBS' ");
	$cpf = singRec("select id, type, amount from epplive.bcs_employee_ad where emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and {$asOnSql} between eff_date and exp_date and ad_code = 'CPF' ");
	$cbon = singRec("select id, type, amount from epplive.bcs_employee_ad where emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and {$asOnSql} between eff_date and exp_date and ad_code = 'CBON' ");
	$cgra = singRec("select id, type, amount from epplive.bcs_employee_ad where emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and {$asOnSql} between eff_date and exp_date and ad_code = 'CGRA' ");
	$cesi = singRec("select id, type, amount from epplive.bcs_employee_ad where emp_code = '" . $OFFER_INFO['EMP_CODE'] . "' and {$asOnSql} between eff_date and exp_date and ad_code = 'CESI' ");

// PHP 8: empty lookups (no payroll rows yet) must be numbers for the sums below.
foreach (['mbas', 'mctc', 'SAL_TOTAL', 'pfbas', 'cpf', 'cbon', 'cgra', 'cesi'] as $salaryVar) {
	$$salaryVar = is_array($$salaryVar ?? null) ? $$salaryVar : [];
	$$salaryVar['ID'] = $$salaryVar['ID'] ?? '';
	$$salaryVar['TYPE'] = $$salaryVar['TYPE'] ?? '';
	$$salaryVar['AMOUNT'] = (float)($$salaryVar['AMOUNT'] ?? 0);
	$$salaryVar['AMT'] = (float)($$salaryVar['AMT'] ?? 0);
}

	//exit;
	//PF
	$cpfval = 0;
	$pf_monthly = 0;
	$pf_yearly = 0;
	if ($cpf['ID']) {
		if ($cpf['TYPE'] == 'P') {
			$cpfval = round((($mbas['AMOUNT'] * $cpf['AMOUNT']) / 100), 0);
			$pfvar = number_format($cpf['AMOUNT'] + 0, 2, '.', '');
		} else {
			$cpfval = round((($cpf['AMOUNT']) * 12 / 100), 0);
			$pfvar = '12.00';
		}
		//$pfvar = number_format($cpf['AMOUNT']+0,2,'.','');
		$pf_monthly = $cpfval;
		$pf_yearly = $cpfval * 12;
	}
	//BON
	$cbonval = 0;
	$bonus_monthly = 0;
	$bonus_yearly = 0;
	if ($cbon['ID']) {
		if ($cbon['TYPE'] == 'P') {
			$cbonval = round((($mbas['AMOUNT'] * $cbon['AMOUNT']) / 100), 0);
			$bonvar = number_format($cbon['AMOUNT'] + 0, 2, '.', '');
		} else {
			$cbonval = round((($cbon['AMOUNT']) / 12), 0);
			$bonvar = '';
		}
		$bonus_monthly = $cbonval;
		$bonus_yearly = $cbonval * 12;
	}
	//GRA
	$cgraval = 0;
	$gratuity_monthly = 0;
	$gratuity_yearly = 0;
	if ($cgra['ID']) {
		if ($cgra['TYPE'] == 'P') {
			$cgraval = round((($mbas['AMOUNT'] * $cgra['AMOUNT']) / 100), 0);
			$gravar = number_format($cgra['AMOUNT'] + 0, 2, '.', '');
		} else {
			$cgraval = round((($cgra['AMOUNT']) / 12), 0);
			$gravar = '';
		}
		$gratuity_monthly = $cgraval;
		$gratuity_yearly = $cgraval * 12;
	}
	//ESI
	$cesival = 0;
	$esi_monthly = 0;
	$esi_yearly = 0;
	if ($cesi['ID']) {
		if ($cesi['TYPE'] == 'P') {
			$cesival = round((($mbas['AMOUNT'] * $cesi['AMOUNT']) / 100), 0);
		} else {
			$cesival = round((($cesi['AMOUNT']) / 12), 0);
		}
		$esi_monthly = $cesival;
		$esi_yearly = $cesival * 12;
	}
	$ctcVal = 0;
	$ctc_total_m = number_format((($SAL_TOTAL['AMT'] + $pf_monthly + $bonus_monthly + $gratuity_monthly + $esi_monthly) / 100000), 2);
	$ctc_total_a = number_format((($SAL_TOTAL['AMT'] + $pf_monthly + $bonus_monthly + $gratuity_monthly + $esi_monthly) * 12 / 100000), 2);
	$all_html = '<table class="table table-borderless" width="100%" border="0">
	<thead>
		<tr>
			<th width="5%"><b>No</b></th>
			<th width="60%"><b>Allowance Type</b></th>
			<th width="35%"><b>Allowance Amount</b></th>
		</tr>
	</thead>
	<tbody>
		' .
$cnt = 1;
foreach ($sqlallow as $ds) {
$all_html .= '<tr>
						<td width="5%" align="left">' . $cnt++ . '</td>
						<td width="60%">' . $ds['ADESC'] . '</td>
						<td width="35%"> Rs.' . $ds['ALLOW_AMOUNT'] . ' ' . ucwords(strtolower($ds['ADD_INFO'])) . '</td>
					</tr>';
}
$all_html .= '
	</tbody>
</table>';
$rei_html = '<table class="table table-borderless" width="100%" border="0">
	<thead>
		<tr>
			<th width="5%"><b>No</b></th>
			<th width="60%"><b>Allowance Type</b></th>
			<th width="35%"><b>Allowance Amount</b></th>
		</tr>
	</thead>
	<tbody>
		' .
$cnt = 1;
foreach ($sqlrei as $ds) {
$rei_html .= '<tr>
<td width="5%" align="left">' . $cnt++ . '</td>
<td width="60%">' . $ds['ADESC'] . '</td>
<td width="35%"> Rs.' . $ds['ALLOW_AMOUNT'] . ' ' . ucwords(strtolower($ds['ADD_INFO'])) . '</td>
</tr>';
}
$rei_html .= '
	</tbody>
</table>
';
//set margins
	//$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
	if ($COMPANY['COMP_ID'] == 1) {
		$top_margin = 32;
		$bot_margin = 25;
	} elseif ($COMPANY['COMP_ID'] == 2) {
		$top_margin = 32;
		$bot_margin = 40;
	} elseif ($COMPANY['COMP_ID'] == 6) {
		$top_margin = 60;
		$bot_margin = 30;
	}

	$pdf->SetMargins(18, $top_margin, 12);  //left, top, right
	$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
	$pdf->SetFooterMargin($bot_margin);
	//set auto page breaks
	$pdf->SetAutoPageBreak(TRUE, $bot_margin);
	$pdf->AddPage();
	$pdf->setListIndentWidth(4);
	$currency_object = new Currency();
	// exit;
	$prob_days = $currency_object->get_number_to_text($JOINING_INFO['ETYPE_PERIOD']);
	
	$tenure_type = '';
	$tnu_name = '';

	if($JOINING_INFO['ETYPE_ID'] == 5) 
    	{ 
			$tenure_type = 'You will be on probation for a period of '.$prob_days.'  months from the date of joining which may be extended for a further period of three months. 
            				During the probation period, if your performance is found to be unsatisfactory or if you are found to be overall unsuitable, your probation will be terminated without notice. 
                            Your services shall automatically stand terminated on the last day of probation period, unless it is extended or your services are confirmed by a separate letter.' ;
    		$tnu_name = '';
    	}
	elseif ($JOINING_INFO['ETYPE_ID'] == 6) 
    	{
			$tenure_type = 'You will be on training for a period of '.$prob_days.'  months from the date of joining which may be extended for a further period of three months. 
            				During the training  period, if your performance is found to be unsatisfactory or if you are found to be overall unsuitable, your training period will be terminated without notice. 
                            Your services shall automatically stand terminated on the last day of training period, unless it is extended or your services are confirmed by a separate letter.' ;
    		$tnu_name = 'Trainee';
    	}

    
		//print_r("Normal");exit;
		$result = ' 
			<style>
			p {
			text-align: justify;
			line-height 16px;
			}
			li { 
			color: black;
			/
			</style>
			<br><br><br><br><br></br>
			<table>
			<tr>
			<td align="left">Ref  : ' . ($refNo ? $refNo : $DOC_INFO['DOC_REF']) . '</td><td align="right">Date  :   ' . ($refNo ? $currentDate : (!empty($DOC_INFO['CHG_ON']) ? date("d-M-Y", strtotime($DOC_INFO['CHG_ON'])) : $currentDate)) . '</td></tr>
			</table><br><br>
			To,<br>
			' . ucwords(strtolower(getEmpInfoByCode($OFFER_INFO['EMP_CODE']))) . '.<br>
			' . ucwords(strtolower(wordwrap($OFFER_INFO['ADDRESS'], 40, "<br>\n"))) . ', <br/>
			' . ucwords(strtolower($OFFER_INFO['CITY'])) . ' - ' . $OFFER_INFO['PINCODE'] . ' <br/>
			Email : ' . $OFFER_INFO['COM_EMAIL'] . ' <br/>
			Mobile : ' . $OFFER_INFO['CELL'] . ' 
			<p>Dear ' . ucwords(strtolower($OFFER_INFO['TITE'])) . ' ' . ucwords(strtolower($OFFER_INFO['FNAME'])) . ',</p>
			<span style="text-align:justify; line-height: 15px;">With reference to your application and the subsequent interview you had with us, the Management is pleased to appoint you on the following terms and conditions with effect from ' . date("F d Y", strtotime($OFFER_INFO['DOJ'])) . '.</span>
			<ol><span style="text-align:justify; line-height: 20px;">
				<li><span style="text-align:justify; line-height: 15px;">Designation: <b>' . $tnu_name . ' ' . $empres['DS'] . '</b> Division: ' . $empoff['DIV'] . ' ' . (($locnm == "Country") ? "" :  $locnm[$Org_Info['EMP_LEVEL']]) . ' - <b>' . ucwords(strtolower($empoff['LOC'])) . '</b>.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will report to ' . ucwords(strtolower(getEmpInfoByCode(findParentOrgEmp($empCode)))) . ' - ' . ucwords(strtolower($bossoff['DESIG'])) . ' or any other person directed by the undersigned.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">' . (($locnm == "Country") ? "" :  "Your " . $locnm[$Org_Info['EMP_LEVEL']] . " for the time being will be " . ucwords(strtolower($empoff['LOC'])) . ".") . ' Your services however are transferable anywhere in India.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">Your Job Description and Responsibilities will be as per Annexure "B" enclosed.
				<ol type = "a">
				<li>Any other assignment that may be allotted to you concerning the Company and its Associate Companies.</li>
				<li>Your assignment and responsibilities are liable to be changed according to the requirements of the Management and you will carry out all such work/responsibilities as may be assigned to you from time to time.</li>
				</ol>
				</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will work for 6 days in a week and will report for work at such places as may be instructed by your superiors.</span></li>
				<li><span style="text-align:justify; line-height: 15px;">'. $tenure_type .'</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will be entitled for an Annual CTC of Rs.' . $ctc_total_a . ' Lac per annum. A detailed breakup of your CTC and other entitlements will be as per Annexures “A” enclosed.</li></span>
				<span style="text-align:justify; line-height: 15px;">
					<ol type = "a">
						' . ($sqlallow ? "<li>In addition to the above, you will be eligible for the following field coverage allowances: <br>  " . $all_html . "</li>" : "<li>Apart from the CTC mentioned above, you will not be entitled for any other allowance.</li>") . ' 
						' . ($sqlrei ? "<li>Besides above mentioned allowances you may also claim the following: <br> " . $rei_html . " <br> To get reimbursement of above expenses, you are requested to procure & provide us relevant bills (duly sealed & signed by the vendor) along with your expense statement.</li>" : "") . '
					</ol>
				</span>
				<li><span style="text-align:justify; line-height: 15px;">You are required to travel outside ' . ucwords(strtolower($Org_Info['LOC_NAME'])) . ' as per directives of the undersigned or any other person directed by undersigned.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">This appointment and the continuance is subject to your being found and remaining medically (physically and mentally) fit by our Medical Officer. On confirmation as stated above, you will be placed in the suitable grade of consolidated salary. You will also be entitled to the benefits of Leave, Bonus etc. as per the Statutory Rules. </li></span>
				<li><span style="text-align:justify; line-height: 15px;">Increment will depend mainly on successful and satisfactory completion of your assignments within the stipulated timeline and as per the Company protocol. It may be withheld in case the standard of work and performance are found unsatisfactory or may be accelerated if the same is adjudged to be commendable in the discretion of the undersigned.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will be required to attend your duties as and when required and at such timings and in such shift/s as may be decided by the Company depending upon the requirements of the Company from time to time.  It should not be presumed that the scope of your duties is circumscribed or limited by your designation.  It should be clearly understood that we reserve our right to allot you any type of duties as may be decided by us from time to time.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You shall not give out during the service in the Company and thereafter to anyone by word or mouth or otherwise particulars on details of sales and marketing strategies, manufacturing process, technical know-how, security arrangements, administrative and/or organisational matter of a confidential and secret nature which it may be your personal privilege to know by virtue of your being our employee.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">Except in condition of explicit and written directions from the appropriate authority of the Company, you or your relatives / friends / kin, shall not accept, directly or indirectly, any money, objects of value, or favors / discounts, from any person or Company that has or is doing or seeking business with the Company. All official payments / transactions made on behalf of the Company must be reported to the reporting Manager promptly.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You shall not encourage or assist in any manner, in the misuse of Company funds, irrespective of the amount involved, including, the misappropriation of such funds for her / his personal.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will devote your entire time to the work of the company. Without prior written consent of the Management, you will not engage in or serve directly or indirectly whether as principal, agent, partner, or employee or in any other capacity either full time or part time, either honorary or on remuneration in any business, trade, profession, or organization other than the business of the company. You will not seek membership of any local or public body without first obtaining specific written consent from the Management.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">Any payment arising out of loss or damage to the Company property due to your negligence or misbehavior or due to commission or omission on your part shall be duly recovered from your CTC.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will in all respects be governed by the rules and regulations applicable from time to time to your rank/category of employees.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">You will automatically retire on attaining the age of ' . $OFFER_INFO['RETIRE_AGE'] . ' years.  An extension may, however, be given at the discretion of the Management.</li></span>
				<li><span style="text-align:justify; line-height: 15px;">If either of the parties desire to terminate this appointment after confirmation, it can be done by one months notice or payment in lieu thereof on either side.  Any dispute arising out of this appointment will subject to Jurisdiction of Courts in Mumbai to the exclusion of the other Courts.</li></span>
			</ol>
				<span style="text-align:justify; line-height: 15px;">If the above terms & conditions are acceptable to you, kindly return the duplicate copy of this letter along with the copy of the Job Description & Responsibilities duly signed by you in token of acceptance of the above terms and conditions. <br><br>We welcome you in our organisation and trust that your association with us will be a long and productive one.</span>';
	
	$pdf->writeHTML($result, true, 0, true, true);
	$result = '</br></br></br></br>
		<p>Yours faithfully,<br>
		For <b>' . strtoupper($COMPANY['COMP_DESC']) . '</b><br><br><br><br><br>
		<table>
		<tr>
		<td align="left"><p><b>'. ucwords(strtolower(getEmpInfoByCode($appoint_sign['EMP_CODE']))) .' <br>
' . $appoint_sign['DESIG'] . ' - ' . $appoint_sign['DEPT'] . ' </b></p></td>		
		</tr>
		<tr>
		<td align="right"><p>(Signature of ' . ucwords(strtolower($OFFER_INFO['FNAME'] . ' ' . $OFFER_INFO['LNAME'])) . ')</p></td>
		</tr>
		</table>
		Encl.: Job Description & Responsibilities.</p>';
	$pdf->writeHTML($result, true, 0, true, true);
	// Salary Breakup
	$pdf->AddPage();
	//set margins
	//$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
	if ($COMPANY['COMP_ID'] == 1) {
		$top_margin = 32;
		$bot_margin = 25;
	} elseif ($COMPANY['COMP_ID'] == 2) {
		$top_margin = 32;
		$bot_margin = 40;
	} elseif ($COMPANY['COMP_ID'] == 6) {
		$top_margin = 60;
		$bot_margin = 30;
	}
	$pdf->SetMargins(18, $top_margin, 12);  //left, top, right
	$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
	$pdf->SetFooterMargin($bot_margin);
	//set auto page breaks
	$pdf->SetAutoPageBreak(TRUE, $bot_margin);
	$result  = '<h3 align="center"><b><u>Annexure - "A"</u></b></h3>';
	$result  .= '<h3 align="center"><b><u>Salary Breakup</u></b></h3>';
	$result .= '<style type="text/css">
		table{
      	width:100%;
		}
		</style>
		<p>
		</br><table border="1" cellpadding="10px" width="100%">
		<thead>
		<tr>
		<td rowspan="2" width="40%" align="center"><b>Particulars</b></td>
		<td rowspan="2" width="15%" align="center"><b>Rate</b></td>
		<td colspan="2" width="40%" align="center"><b>    Amount (Rs.)</b></td>
		</tr>
		<tr align="center">
		<td width="20%" align="center"><b>  Monthly</b></td>
		<td width="20%" align="center"><b>  Annual</b></td>
		</tr>
		</thead>
		<tbody>
		';
	$cnt = 1;
	foreach ($CTC_table as $ds) {
		$result .= '<tr>
			<td width="5%" align="center">' . $cnt++ . '</td>
			<td width="35%">' . $ds['SH_DESCR'] . '</td>
			<td width="15%"></td>
			<td width="20%" align="right" >' . $ds['AMOUNT'] . '</td>
			<td width="20%" align="right">' . ($ds['AMOUNT'] * 12) . '</td>
			</tr>';
		$total += $ds['AMOUNT'];
		if ($ds['AD_CODE'] == "MBAS") {
			$mbas = $ds['AMOUNT'];
		}
	}
	//$result.='<tr><td>'.$total.'</td><tr>';
	$result .= '
		</tbody>
		</table>';
	if ($cesi['ID']) {
		$result .= '<table border="1" cellpadding="10px" width="100%">
			<tbody>
			<tr>
			<td width="5%"></td>
			<td width="35%"><b>Gross Salary</b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b>' . $total . '</b></td>
			<td width="20%" align="right"><b>' . ($total * 12) . '</b></td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%"><b><u>Add :-</u></b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b></b></td>
			<td width="20%" align="right"><b></b></td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%" >PF (Employer\'s part)</td> 
			<td width="15%" align="center">' . $pfvar . '</td>
			<td width="20%" align="right">' . number_format($pf_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($pf_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%">Bonus</td>
			<td width="15%" align="center">8.33%</td>
			<td width="20%" align="right">' . number_format($bonus_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($bonus_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%">Gratuity</td>
			<td width="15%" align="center">5.00%</td>
			<td width="20%" align="right">' . number_format($gratuity_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($gratuity_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%">ESI</td>
			<td width="15%" align="center">5.00%</td>
			<td width="20%" align="right">' . number_format($esi_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($esi_yearly, 0, '', '') . '</td>
			</tr>
			<tr><td width="5%"></td>
			<td width="35%" align="right"><b>Total CTC</b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b>' . number_format($pf_monthly + $bonus_monthly + $gratuity_monthly + $esi_monthly + $total, 0, '', '') . '</b></td>
			<td width="20%" align="right"><b>' . number_format(($pf_yearly + $bonus_yearly + $gratuity_yearly + $esi_yearly + ($total * 12)), 0, '', '') . '</b></td>
			</tr>
			<tr>
			<td colspan="5">
			</td>
			</tr>
			</tbody>
			</table></p>';
	} else {
		$result .= '<table border="1" cellpadding="10px" width="100%">
			<tbody>
			<tr>
			<td width="5%"></td>
			<td width="35%"><b>Gross Salary</b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b>' . $total . '</b></td>
			<td width="20%" align="right"><b>' . ($total * 12) . '</b></td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%"><b><u>Add :-</u></b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b></b></td>
			<td width="20%" align="right"><b></b></td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%" >PF (Employer\'s part)</td> 
			<td width="15%" align="center">' . $pfvar . '%</td>
			<td width="20%" align="right">' . number_format($pf_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($pf_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%">Bonus</td>
			<td width="15%" align="center">' . $bonvar . '%</td>
			<td width="20%" align="right">' . number_format($bonus_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($bonus_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%">Gratuity</td>
			<td width="15%" align="center">' . $gravar . '%</td>
			<td width="20%" align="right">' . number_format($gratuity_monthly, 0, '', '') . '</td>
			<td width="20%" align="right">' . number_format($gratuity_yearly, 0, '', '') . '</td>
			</tr>
			<tr>
			<td width="5%"></td>
			<td width="35%" align="right"><b>Total CTC</b></td>
			<td width="15%"></td>
			<td width="20%" align="right"><b>' . number_format($pf_monthly + $bonus_monthly + $gratuity_monthly + $total, 0, '', '') . '</b></td>
			<td width="20%" align="right"><b>' . number_format(($pf_yearly + $bonus_yearly + $gratuity_yearly + ($total * 12)), 0, '', '') . '</b></td>
			</tr>
			<tr>
			<td colspan="5">
			</td>
			</tr>
			</tbody>
			</table></p>';
	}
	$result  .= '		
		<p>Yours faithfully,<br>
		For <b>' . strtoupper($COMPANY['COMP_DESC']) . '</b><br><br><br><br><br><br><br>
		<table>
		<tr>
		<td align="left"><p><b>' . ucwords(strtolower(getEmpInfoByCode($appoint_sign['EMP_CODE']))) . ' <br>
		' . $appoint_sign['DESIG'] . ' - ' . $appoint_sign['DEPT'] . ' </b></p></td>
		</tr>
		<tr>
		<td align="right"><p>(Signature of ' . ucwords(strtolower($OFFER_INFO['FNAME'] . ' ' . $OFFER_INFO['LNAME'])) . ')</p></td>
		</tr>
		</table>
		</p>';
	$pdf->writeHTML($result, true, 0, true, true);
	// Responsibilities
	$pdf->AddPage();
	$pdf->setCellHeightRatio(1.4);
	//set margins
	//$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
	if ($COMPANY['COMP_ID'] == 1) {
		$top_margin = 32;
		$bot_margin = 25;
	} elseif ($COMPANY['COMP_ID'] == 2) {
		$top_margin = 32;
		$bot_margin = 40;
	} elseif ($COMPANY['COMP_ID'] == 6) {
		$top_margin = 60;
		$bot_margin = 30;
	}
	$pdf->SetMargins(18, $top_margin, 12);  //left, top, right
	$pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
	$pdf->SetFooterMargin($bot_margin);
	//set auto page breaks
	$pdf->SetAutoPageBreak(TRUE, $bot_margin);
	$result  = '<h3 align="center"><b><u>Annexure - "B"</u></b></h3>';
	$result  .= '<h3 align="center"><b><u>Job Responsibilities</u></b></h3>';
	$result  .= '
		<style>
		p {
		text-align: justify
		}
		</style>
		' . html_entity_decode($JD_RESP['DESCR']) . ' <br>';
	$pdf->writeHTMLCell(0, 0, '', '', $result, '', 1, 0, true, 'L', true);
	$pdf->setCellHeightRatio(1.2);
	$result  = '
		<p>Yours faithfully,<br>
		For <b>' . strtoupper($COMPANY['COMP_DESC']) . '</b><br><br><br><br><br><br>
		<table>
		<tr>
		<td align="left"><p><b>' . ucwords(strtolower(getEmpInfoByCode($appoint_sign['EMP_CODE']))) . ' <br>
		' . $appoint_sign['DESIG'] . ' - ' . $appoint_sign['DEPT'] . ' </b></p></td>		
		</tr>
		<tr>
		<td align="right"><p>(Signature of ' . ucwords(strtolower($OFFER_INFO['FNAME'] . ' ' . $OFFER_INFO['LNAME'])) . ')</p></td>
		</tr>
		</table>
		</p>';
	$pdf->writeHTMLCell(0, 0, '', '', $result, '', 1, 0, true, 'L', true);
	ob_end_clean();
	//$pdf->writeHTML($result, true, 0, true, true);
appointmentFinishPdf($pdf, $DOC_INFO, 'appointment_letter_field.pdf');