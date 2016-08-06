<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::get('/graphs/{type?}/{graph?}', 'GraphController@index');
Route::get('/', 'SearchController@getIndex');
Route::get('/search', 'SearchController@getSearch');
Route::get('/doctrine', 'SearchController@getDoctrine');

Route::post('/slack/invite', 'SlackController@sendInvite');

Route::controller('/boards/reviews/{boardId?}', 'ReviewController');
Route::controller('/gerrit', 'GerritController');
Route::controller('/management', 'ManagementController');
Route::controller('/sprint', 'SprintController');
Route::controller('/slack', 'SlackController');
Route::controller('/utility', 'UtilityController');
