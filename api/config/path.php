<?php

require_once __DIR__ . "/env.php";

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/

define("PUBLIC_PATH",rtrim($_ENV["PUBLIC_PATH"], "/"));

define("PUBLIC_URL", rtrim($_ENV["PUBLIC_URL"], "/"));

/*
|--------------------------------------------------------------------------
| DOWNLOADS
|--------------------------------------------------------------------------
*/

define("PUBLIC_DOWNLOAD_PATH", PUBLIC_PATH . "/downloads");

define("PUBLIC_DOWNLOAD_URL", PUBLIC_URL . "/downloads");

/*
|--------------------------------------------------------------------------
| DOCUMENT STORAGE
|--------------------------------------------------------------------------
*/

define("DOCUMENT_ROOT", rtrim($_ENV["DOCUMENT_ROOT"], "/"));

define("INCOME_TAX_PATH", DOCUMENT_ROOT . "/incometax");

define("TEMP_ZIP_PATH", DOCUMENT_ROOT . "/temp_zip");

define("TEMP_DECLARATION_PATH", DOCUMENT_ROOT . "/temp_declaration");

define("ITR_JOB_LOG_PATH", DOCUMENT_ROOT . "/itr/jobs");

/*
|--------------------------------------------------------------------------
| PROFILE CHANGE REQUEST UPLOADS (under DOCUMENT_ROOT, served by common/view_document.php)
|--------------------------------------------------------------------------
*/

define("PROFILE_UPLOADS_DIR", "uploads");

define("BANK_DOCS_DIR", PROFILE_UPLOADS_DIR . "/bank_document");

define("MEMBER_DOCS_DIR", PROFILE_UPLOADS_DIR . "/member_document");

define("ADDRESS_PROOF_DIR", PROFILE_UPLOADS_DIR . "/address_proof");

/*
|--------------------------------------------------------------------------
| HRMS DOCUMENT UPLOADS (legacy hradmin layout)
|--------------------------------------------------------------------------
| *_DIR values are relative to HRMS_DOC_ROOT and are what DOC_PATH columns
| store, so they must stay in step with the legacy server.
*/

define("HRMS_DOC_ROOT", rtrim($_ENV["HRMS_DOC_ROOT"], "/"));

define("HRMS_LEGACY_DOC_URL", rtrim($_ENV["HRMS_LEGACY_DOC_URL"], "/") . "/");

define("EPORTAL_LEGACY_URL", rtrim($_ENV["EPORTAL_LEGACY_URL"], "/") . "/");

define("EMP_DOCS_DIR", "input/emp_docs");

define("JD_DOCS_DIR", "input/jd_docs");

define("POLICY_DOCS_DIR", "input/policy_doc");

define("GATEPASS_DOCS_DIR", "input/gatepass");

define("CAREERS_LEGACY_DOCS_URL", HRMS_LEGACY_DOC_URL . "careers/");

/** Absolute path of an HRMS document path such as "input/emp_docs/x.pdf". */
function hrmsDocPath($relativePath)
{
    return HRMS_DOC_ROOT . "/" . ltrim((string)$relativePath, "/");
}

/** Absolute path of a file under DOCUMENT_ROOT such as "uploads/bank_document/x.pdf". */
function documentRootPath($relativePath)
{
    return DOCUMENT_ROOT . "/" . ltrim((string)$relativePath, "/");
}
