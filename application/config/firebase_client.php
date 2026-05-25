<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Firebase Client (REST) Configuration
|--------------------------------------------------------------------------
| Web API Key for the identitytoolkit REST API. Used by the server to
| verify an email + password against Firebase Authentication
| (the Admin SDK can't verify passwords directly — that's a client op).
|
| Source: Firebase Console → Project settings → General → Web API Key.
| Same key as embedded in the Android/iOS google-services.json.
|
| Override with the FIREBASE_WEB_API_KEY environment variable in
| production deployments.
*/

$config['firebase_project_id']   = getenv('FIREBASE_PROJECT_ID') ?: 'graderadmin';
$config['firebase_web_api_key']  = getenv('FIREBASE_WEB_API_KEY') ?: 'AIzaSyDK0gfLhV_WJGxEzxvH61KAXVtasZcc8Zs';
$config['firebase_auth_timeout'] = 10; // seconds
