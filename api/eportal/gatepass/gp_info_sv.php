<?php

require_once "gp_head.php";

if($data['getGpdata']==true)
{
    $idd = $data['id'] ?? "";
    $res = singRec("SELECT * FROM EPT_EMPLOYEE_GPASS WHERE ID='".$idd."'");
    $empCodeFromRow = $res['EMP_CODE'] ?? $empCode ?? null;
    //printDetails($res);
    $returnArr = [];

    $returnArr["hidden"] = [
        [
            "name" => "ID",
            "id" => "ID",
            "value" => $res['ID']
        ],
        [
            "name" => "OUT_TYPE_HIDDEN",
            "id" => "OUT_TYPE_HIDDEN",
            "value" => $res['OUT_TYPE']
        ],
        [
            "name" => "EMP_CODE",
            "id" => "EMP_CODE",
            "value" => $empCodeFromRow
        ]    
    ];
    
    
    $returnArr["form_data"] = [
        [
            "name" => "GPASS_DATE",
            "id" => "GPASS_DATE",
            "value" => $res['GPASS_DATE'] ?? "",
            "is_readonly" => true
        ],
        [
            "name" => "OUT_TYPE",
            "id" => "OUT_TYPE",
            "value" => $res['OUT_TYPE'] ?? "",
            "options" => [
                'OI'=> 'In/Out same day',
                'OD'=>'Out for full day',
                'FO'=> 'First Half Out',
                'SO'=>'Second Half Out',
                'FW' => 'Field Work',
                'TO' => 'Tour'
            ],
            "is_readonly" => false
        ],
        [
            "name" => "REMARKS",
            "id" => "REMARKS",
            "value" => $res['REMARKS'] ?? "",
            "is_readonly" => false
        ],
        [
            "name" => "POST_REMARKS",
            "id" => "POST_REMARKS",
            "value" => $res['POST_REMARKS'] ?? "",
            "is_readonly" => false
        ],
    ];

    $returnArr["form_data"]["employee_name"] = getEmpInfoByCode($empCodeFromRow);

    /* ---------------------------------
        COMMON CLASS DEFINITIONS
    ----------------------------------*/

    // $className = [
    //     "Divs" => [
    //         "small" => "col-lg-6 mb-3",
    //         //"medium" => "col-md-6",
    //         "large" => "col-lg-12 mb-3"
    //     ],
    //     "Labels" => [
    //         "general" => "fw-bold label-req"
    //     ],
    //     "Fields" => [
    //         "TEXT" => "form-control",
    //         "SELECT" => "select2 form-control",
    //         "TEXTAREA" => "form-control"
    //     ]
    // ];

    /* ---------------------------------
        Status
    ----------------------------------*/
    $status = $res['STATUS'] ?? null;

    $returnArr["form_data"]["GPSTATUS"] = (!empty($status) && isset($statusMap[$status]))
        ? $statusMap[$status]
        : null;   // or "" if you prefer empty string

    /* ---------------------------------
        FIELD DEFINITIONS - TEXT
    ----------------------------------*/
    // $returnArr["var"]["type"]["TEXT"] = [
    //     "EMP_NAME" => [
    //         "type" => "TEXT",
    //         "label" => "Employee Name",
    //         "labelClassName" => $className["Labels"]["general"],
    //         "name" => "EMP_NAME",
    //         "id" => "EMP_NAME",
    //         "value" => getEmpInfoByCode($empCodeFromRow) ?? '',
    //         "rowPlacement" => "row-1|col-2",
    //         "family" => [],
    //         "dependsOn" => "",
    //         "onChangeFunc" => null,
    //         "divClassName" => $className["Divs"]["small"],
    //         "fieldClassName" => $className["Fields"]["TEXT"],
    //         "disabled" => "Yes",
    //         "PleaseSelect" => "No",
    //         "PlaceHolder" => "",
    //         "onBlurFunc" => "",
    //         "isRequired" => "Yes",
    //     ],
    //     "GPASS_DATE" => [
    //         "type" => "TEXT",
    //         "label" => "Outdoor Date",
    //         "labelClassName" => $className["Labels"]["general"],
    //         "name" => "GPASS_DATE",
    //         "id" => "GPASS_DATE",
    //         "value" => $res['GPASS_DATE'] ?? '',
    //         "rowPlacement" => "row-1|col-2",
    //         "family" => [],
    //         "dependsOn" => "",
    //         "onChangeFunc" => null,
    //         "divClassName" => $className["Divs"]["small"],
    //         "fieldClassName" => $className["Fields"]["TEXT"],
    //         "disabled" => "Yes",
    //         "PleaseSelect" => "No",
    //         "PlaceHolder" => "",
    //         "onBlurFunc" => "",
    //         "isRequired" => "Yes",
    //     ]
    // ];

    /* ---------------------------------
        TEXTAREA
    ----------------------------------*/

    // $returnArr["var"]["type"]["TEXTAREA"] = [

    //     "REMARKS" => [
    //         "type" => "TEXTAREA",
    //         "label" => "Remarks",
    //         "labelClassName" => $className["Labels"]["general"],
    //         "name" => "REMARKS",
    //         "id" => "REMARKS",
    //         "value" => $res['REMARKS'] ?? '',
    //         "rowPlacement" => "row-2|col-1",
    //         "family" => [],
    //         "dependsOn" => "",
    //         "onChangeFunc" => null,
    //         "divClassName" => $className["Divs"]["large"],
    //         "fieldClassName" => $className["Fields"]["TEXTAREA"],
    //         "disabled" => "No",
    //         "PleaseSelect" => "No",
    //         "isRequired" => "Yes",
    //     ],

    //     "POST_REMARKS" => [
    //         "type" => "TEXTAREA",
    //         "label" => "Post Remarks",
    //         "labelClassName" => $className["Labels"]["general"],
    //         "name" => "POST_REMARKS",
    //         "id" => "POST_REMARKS",
    //         "value" => $res['POST_REMARKS'] ?? '',
    //         "rowPlacement" => "row-3|col-1",
    //         "family" => [],
    //         "dependsOn" => "",
    //         "onChangeFunc" => null,
    //         "divClassName" => $className["Divs"]["large"],
    //         "fieldClassName" => $className["Fields"]["TEXTAREA"],
    //         "disabled" => "Yes",
    //         "PleaseSelect" => "No",
    //         "isRequired" => "Yes",
    //     ]
    // ];

    /* ---------------------------------
        HIDDEN FIELDS
    ----------------------------------*/

    // $returnArr["var"]["type"]["HIDDEN"] = [

    //     "ID" => [
    //         "type" => "HIDDEN",
    //         "name" => "ID", 
    //         "id" => "ID", 
    //         "value" => $res['ID'] ?? null
    //     ],
    //     "OUT_TYPE_HIDDEN" => [
    //         "type" => "HIDDEN",
    //         "name" => "OUT_TYPE_HIDDEN", 
    //         "id" => "OUT_TYPE_HIDDEN", 
    //         "value" => $res['OUT_TYPE'] ?? null
    //     ],
    //     "EMP_CODE" => [
    //         "type" => "HIDDEN",
    //         "name" => "EMP_CODE", 
    //         "id" => "EMP_CODE", 
    //         "value" => $empCodeFromRow ?? null
    //     ]
    // ];
    
    // if(isset($data["hiddenTaskId"])){
    //     $returnArr["var"]["type"]["HIDDEN"]["TASK_ID"] = 
    //     [
    //         "type" => "HIDDEN",
    //         "name" => "TASK_ID", 
    //         "id" => "TASK_ID", 
    //         "value" => $data["hiddenTaskId"] ?? null
    //     ];
    // }

    /* ---------------------------------
        SELECT FIELDS
    ----------------------------------*/
    // $returnArr["var"]["type"]["SELECT"] = [
    //     "OUT_TYPE" => [
    //         "type" => "SELECT",
    //         "label" => "Out Type",
    //         "labelClassName" => $className["Labels"]["general"],
    //         "name" => "OUT_TYPE", 
    //         "id" => "OUT_TYPE", 
    //         "value" => $res['OUT_TYPE'] ?? "",
    //         "rowPlacement" => "row-1|col-1",
    //         "family" => [],
    //         "dependsOn" => null,
    //         "onChangeFunc" => "",
    //         "divClassName" => $className["Divs"]["small"],
    //         "fieldClassName" => $className["Fields"]["SELECT"],
    //         "disabled" => "No",
    //         "PleaseSelect" => "Yes",
    //         "isRequired" => "Yes",
    //         "options" => [
    //             'OI'=> 'In/Out same day',
    //             'OD'=>'Out for full day',
    //             'FO'=> 'First Half Out',
    //             'SO'=>'Second Half Out',
    //             'FW' => 'Field Work',
    //             'TO' => 'Tour'
    //         ]
    //     ],

    // ];

    echo json_encode([
        "status" => true,
        "pass"   => $returnArr
    ]);

}

ob_end_flush();
