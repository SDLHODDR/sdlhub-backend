<?php

require_once "tb_head.php";

try {

    $data['directurl'] = 1;

    if($data['getTbrdata']==true)
    {
        $returnArr = [];
        $res = [];

        /* ---------------------------------
            GET RECORD (EDIT MODE)
        ----------------------------------*/
        if (!empty($data['id'])) {
            $res = singRec("
                SELECT 
                    ID, SITE_CODE, TRVL_CLASS, TRVL_EMP, EMP_CODE, PERSON_NAME, 
                    decode(TRVL_MODE , 'F' , 'Flight' , 'T' , 'Train' , 'B' , 'Bus') TRVL_MODE, 
                    TRVL_DATE, TRVL_FROM_LOC, TRVL_TO_LOC, TRVL_FT_NAME, TRVL_FT_NO, EVENT_ID, 
                    to_char(TTNT_DEPR_TIME , 'hh24:mi') TTNT_DEPR_TIME, 
                    to_char(TTNT_ARVL_TIME , 'hh24:mi') TTNT_ARVL_TIME, 
                    REMARKS, STATUS, TRVL_TKT_ID 
                FROM EPT_BCS_TRVLTKT_REQUEST 
                WHERE ID = '" . $data['id'] . "'
            ");
        }

        // Step 1: Manager logic (reuse)
        $name  = singRec("
            SELECT EPT_hr_get_emp_mgr('".$empCode."', SYSDATE) AS EMP_CODE 
            FROM dual
        ");
        $name1 = findParentOrgEmp($empCode);
        $manager = !empty($name['EMP_CODE']) ? $name['EMP_CODE'] : $name1;
        
        // Step 2: Final query with categories
        $fetOPtions = getOptionsCustom("
            SELECT emp_code, emp_name, category
            FROM (

                -- Self
                SELECT 
                    '".$empCode."' AS emp_code,
                    EPT_get_emp_name('".$empCode."') AS emp_name,
                    'Self' AS category
                FROM dual

                UNION

                -- My Manager
                SELECT 
                    emp_code,
                    EPT_get_emp_name(emp_code),
                    'My Manager'
                FROM EPT_bcs_employee
                WHERE status = 'A'
                AND emp_code = '".$manager."'

                UNION

                -- My Colleagues
                SELECT 
                    emp_code,
                    EPT_get_emp_name(emp_code),
                    'My Colleague'
                FROM EPT_bcs_employee
                WHERE status = 'A'
                AND report_to = '".$manager."'
                AND emp_code <> '".$empCode."'

                UNION

                -- My Team
                SELECT 
                    emp_code,
                    EPT_get_emp_name(emp_code),
                    'My Team'
                FROM EPT_bcs_employee
                WHERE status = 'A'
                AND report_to = '".$empCode."'

            )
            ORDER BY 
                CASE category
                    WHEN 'Self' THEN 1
                    WHEN 'My Manager' THEN 2
                    WHEN 'My Colleague' THEN 3
                    WHEN 'My Team' THEN 4
                END,
                emp_name
        ");
        //print_r($fetOPtions);
        
        /* ---------------------------------
            COMMON CLASS DEFINITIONS
        ----------------------------------*/
        $className = [
            "Divs" => [
                "small" => "col-lg-6 mb-3",
                "large" => "col-lg-12 mb-3"
            ],
            "Labels" => [
                "general" => "fw-bold label-req"
            ],
            "Fields" => [
                "TEXT" => "form-control",
                "SELECT" => "select2 form-control",
                "TEXTAREA" => "form-control"
            ]
        ];

        /* ---------------------------------
            FETCH CONSOLIDATED EMPLOYEES
        ----------------------------------*/
        

        /* ---------------------------------
            FIELD DEFINITIONS - TEXT
        ----------------------------------*/
        $returnArr["var"]["type"]["TEXT"] = [
            "PERSON_NAME" => [
                "type" => "TEXT",
                "label" => "PERSON NAME",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "PERSON_NAME",
                "id" => "PERSON_NAME",
                "value" => $res['PERSON_NAME'] ?? '',
                "rowPlacement" => "row-1|col-2",
                "family" => [
                    "parent" => "TRVL_EMP",
                    "child" => "first-step-child",
                    "parentBelongsToId" => "O"
                ],
                "dependsOn" => "TRVL_EMP",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "Yes",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "Yes",
            ],
            "Travel_Date" => [
                "type" => "TEXT",
                "label" => "Travel Date",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_DATE",
                "id" => "TRVL_DATE",
                "value" => $res['TRVL_DATE'] ?? '',
                "rowPlacement" => "row-2|col-3",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "Yes"
            ],
            "Travel_From" => [
                "type" => "TEXT",
                "label" => "Travel From",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_FROM_LOC",
                "id" => "TRVL_FROM_LOC",
                "value" => $res['TRVL_FROM_LOC'] ?? '',
                "rowPlacement" => "row-3|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],    
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "Yes"
            ],
            "Travel_To" => [
                "type" => "TEXT",
                "label" => "Travel To",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_TO_LOC",
                "id" => "TRVL_TO_LOC",
                "value" => $res['TRVL_TO_LOC'] ?? '',
                "rowPlacement" => "row-3|col-2",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "Yes",
            ],
            "Flight_Train_Name" => [
                "type" => "TEXT",
                "label" => "Flight / Train Name",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_FT_NAME",
                "id" => "TRVL_FT_NAME",
                "value" => $res['TRVL_FT_NAME'] ?? '',
                "rowPlacement" => "row-3|col-3",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "Yes",
            ],
            "Flight_Train_Number" => [
                "type" => "TEXT",
                "label" => "Flight / Train Number",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_FT_NO",
                "id" => "TRVL_FT_NO",
                "value" => $res['TRVL_FT_NO'] ?? '',
                "rowPlacement" => "row-4|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "",
                "isRequired" => "No",
            ],
            "Suitable_Departure_Onwards" => [
                "type" => "TEXT",
                "label" => "Suitable Departure Onwards",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TTNT_DEPR_TIME",
                "id" => "TTNT_DEPR_TIME",
                "value" => $res['TTNT_DEPR_TIME'] ?? '',
                "rowPlacement" => "row-4|col-2",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "validatetime",
                "isRequired" => "Yes",
            ],
            "Suitable_Arrival_Onwards" => [
                "type" => "TEXT",
                "label" => "Suitable Arrival Onwards",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TTNT_ARVL_TIME",
                "id" => "TTNT_ARVL_TIME",
                "value" => $res['TTNT_ARVL_TIME'] ?? '',
                "rowPlacement" => "row-4|col-3",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["TEXT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "PlaceHolder" => "",
                "onBlurFunc" => "validatetime",
                "isRequired" => "Yes",
            ]
        ];

        /* ---------------------------------
            TEXTAREA
        ----------------------------------*/

        $returnArr["var"]["type"]["TEXTAREA"] = [

            "REMARKS" => [
                "type" => "TEXTAREA",
                "label" => "Remarks",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "REMARKS",
                "id" => "REMARKS",
                "value" => $res['REMARKS'] ?? '',
                "rowPlacement" => "row-5|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["large"],
                "fieldClassName" => $className["Fields"]["TEXTAREA"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "isRequired" => "Yes",
            ]
        ];

        /* ---------------------------------
            HIDDEN FIELDS
        ----------------------------------*/

        $returnArr["var"]["type"]["HIDDEN"] = [

            "ID" => [
                "type" => "HIDDEN",
                "name" => "ID",
                "id" => "ID",
                "value" => $res['id'] ?? ''
            ]
        ];

        /* ---------------------------------
            SELECT FIELDS
        ----------------------------------*/
        $returnArr["var"]["type"]["SELECT"] = [
            "TRVL_EMP" => [
                "type" => "SELECT",
                "label" => "Travelling Person",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_EMP", 
                "id" => "TRVL_EMP", 
                "value" => $res['TRVL_EMP'] ?? "",
                "rowPlacement" => "row-1|col-1",
                "family" => [],
                "dependsOn" => null,
                "onChangeFunc" => "showEmp",
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "Yes",
                "isRequired" => "Yes",
                "options" => [
                    "E"=> 'Employee',
                    "O"=> 'Other'
                ]
            ],
            "EMP_CODE" => [
                "type" => "SELECT",
                "label" => "Employee Code",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "EMP_CODE", 
                "id" => "EMP_CODE", 
                "value" => $res['EMP_CODE'] ?? "",
                "rowPlacement" => "row-1|col-2",
                "family" => [
                    "parent" => "TRVL_EMP", 
                    "child" => "first-child", 
                    "parent-belongsToId" => "E"
                ],
                "dependsOn" => "TRVL_EMP",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "isRequired" => "Yes",
                "options" => $fetOPtions ?? ""
            ],
            "TRVL_MODE" => [
                "type" => "SELECT",
                "label" => "Travel Mode",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_MODE", 
                "id" => "TRVL_MODE", 
                "value" => $res['TRVL_MODE'] ?? "",
                "rowPlacement" => "row-2|col-1",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "isRequired" => "No",
                "options" => [
                    'T'=> 'Train',
                    'F'=>'Flight',
                    'B'=>'Bus'
                ]
            ],
            "TRVL_CLASS" => [
                "type" => "SELECT",
                "label" => "Travel Class",
                "labelClassName" => $className["Labels"]["general"],
                "name" => "TRVL_CLASS", 
                "id" => "TRVL_CLASS", 
                "value" => $res['TRVL_CLASS'] ?? "",
                "rowPlacement" => "row-2|col-2",
                "family" => [],
                "dependsOn" => "",
                "onChangeFunc" => null,
                "divClassName" => $className["Divs"]["small"],
                "fieldClassName" => $className["Fields"]["SELECT"],
                "disabled" => "No",
                "PleaseSelect" => "No",
                "isRequired" => "No",
                "options" => getOptionsCustom("select distinct TRVL_CLASS, TRVL_CLASS from EPT_BCS_TRVL_TICKET order by 1")
            ]
        ];

        if($returnArr || !empty($returnArr)){
            apiResponse(
                true,
                "Ticket booking data fetched successfully.",
                ["pass"   => $returnArr]
            );
        }
       

    }
} catch (Throwable $e) {
    logOracleError($e);
    apiResponse(false, "Unable to fetch outdoorduty form data.", null, 500);
} finally {
    if ($sql___func___con) {
        oci_close($sql___func___con);
    }
}
