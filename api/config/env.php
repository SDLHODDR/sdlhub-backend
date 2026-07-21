<?php

$_ENV["LOCAL_REACT_APP_URL"] = "http://localhost:5173";

$_ENV["APP_URL"] = "http://localhost/sdlhub/public/"; //-- need to remove

$_ENV["API_ROOT_PATH"] = "http://localhost/sdlhub-backend/api";

/*
|--------------------------------------------------------------------------
| Public Download Folder
|--------------------------------------------------------------------------
*/

$_ENV["PUBLIC_PATH"] = "/var/www/html/sdlhub-public";

$_ENV["PUBLIC_URL"] = "http://localhost/sdlhub-public";

$_ENV["PROFILES_URL"] = $_ENV["PUBLIC_URL"] . "/profiles"; //"http://localhost/sdlhub-public/profiles/";

/*
|--------------------------------------------------------------------------
| Documents Root
|--------------------------------------------------------------------------
*/

$_ENV["DOCUMENT_ROOT"] = "/mnt/documents";
