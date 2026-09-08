<?php

define('CURRENT_PORTAL', 'hrms');

require_once __DIR__ . "/../../../config/session.php";
require_once __DIR__ . "/../../../cors.php";
require_once __DIR__ . "/../../../config/db.php";

$conn = db_hrms();
$sql___func___con = $conn;

require_once __DIR__ . "/../../../config/functions.php";
require_once __DIR__ . "/../../../config/utils.php";

header("Content-Type: application/json");

try {

    /*
     * Titles
     */
    $titles = multiRec("
    SELECT TITLE_ID AS VALUE, TITLE_DESC AS LABEL
    FROM HR_TITLES
    ORDER BY TITLE_ID
");


    /*
     * States
     */
    $states = multiRec("
        SELECT
            GSTAT_CODE AS VALUE,
            GSTAT_DESC AS LABEL
        FROM EPPLIVE.BCS_GST_STATES
        ORDER BY GSTAT_DESC
    ");


    /*
     * Countries
     */
    $countries = multiRec("
        SELECT COUNT_CODE AS VALUE, DESCR AS LABEL
FROM EPPLIVE.BCS_COUNTRY
ORDER BY DESCR
    ");


    /*
     * Companies
     */
    $companies = multiRec("
        SELECT
            COMP_ID AS VALUE,
            COMP_DESC AS LABEL
        FROM HR_COMPANY
        ORDER BY COMP_DESC
    ");


    /*
     * Gender
     */
    $genders = multiRec("
        SELECT
            GEND_ID AS VALUE,
            GEND_DESC AS LABEL
        FROM HR_GENDER
        ORDER BY GEND_ID
    ");


    /*
     * Blood Group
     */
    $bloodGroups = multiRec("
        SELECT
            BLD_ID AS VALUE,
            BLD_DESC AS LABEL
        FROM HR_BGROUP
        ORDER BY BLD_ID
    ");


    /*
     * Marital Status
     */
    $maritalStatuses = multiRec("
        SELECT
            MARI_ID AS VALUE,
            MARI_DESC AS LABEL
        FROM HR_MARITAL
        ORDER BY MARI_ID
    ");


    /*
     * Religion
     */
    $religions = multiRec("
        SELECT
            RELI_ID AS VALUE,
            RELI_DESC AS LABEL
        FROM HR_RELIGION
        ORDER BY RELI_ID
    ");


    /*
     * Nationality
     */
    $nationalities = multiRec("
        SELECT
            NATN_ID AS VALUE,
            NATN_DESC AS LABEL
        FROM HR_NATIONALITY
        ORDER BY NATN_DESC
    ");


    /*
     * Employee Type
     */
    $employeeTypes = multiRec("
        SELECT
            E_ID AS VALUE,
            EMP_TYPE AS LABEL
        FROM HR_EMP_TYPE_MST
        ORDER BY E_ID
    ");


    /*
     * Levels
     */
    $levels = multiRec("
        SELECT
            LEVL AS VALUE,
            LEVL_DESC AS LABEL
        FROM HR_EMP_LEVELS
        ORDER BY LEVL
    ");


    /*
     * Bands
     */
    $bands = multiRec("
        SELECT
            OLVL_ID AS VALUE,
            OLVL_DESC AS LABEL
        FROM HR_ORG_LEVEL
        ORDER BY OLVL_ID
    ");


    /*
     * Document Types
     */
    $documentTypes = multiRec("
        SELECT
            DOCTYP_ID AS VALUE,
            DOCTYP_DESC AS LABEL
        FROM HR_DOC_TYPES
        ORDER BY DOCTYP_ID
    ");


    /*
     * Assets
     */
    $assets = multiRec("
        SELECT
            ASSET_ID AS VALUE,
            ASSET_CODE || ' - ' || ASSET_DESC AS LABEL
        FROM HR_ASSET_MST
        ORDER BY LABEL
    ");


    /*
     * Return masters
     */
    apiResponse(
        true,
        'Employee masters fetched successfully.',
        [
            'titles' => $titles,
            'states' => $states,
            'countries' => $countries,
            'companies' => $companies,
            'genders' => $genders,
            'bloodGroups' => $bloodGroups,
            'maritalStatuses' => $maritalStatuses,
            'religions' => $religions,
            'nationalities' => $nationalities,
            'employeeTypes' => $employeeTypes,
            'levels' => $levels,
            'bands' => $bands,
            'documentTypes' => $documentTypes,
            'assets' => $assets
        ],
        200
    );

} catch (Throwable $e) {
    error_log('Employee Masters API Error: ' . $e->getMessage());

    apiResponse(
        false,
        'Unable to fetch employee masters.',
        null,
        500
    );
}