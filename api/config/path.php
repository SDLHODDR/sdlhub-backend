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


