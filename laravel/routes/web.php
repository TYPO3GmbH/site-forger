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
Auth::routes();

Route::get('/graphs/{type?}/{graph?}', 'GraphController@index');
Route::get('/', 'SearchController@getIndex');
Route::get('/search', 'SearchController@getSearch');
Route::get('/doctrine', 'SearchController@getDoctrine');

Route::post('/slack/invite', 'SlackController@sendInvite');

Route::get('/sprint', 'SprintController@getIndex');
Route::get('/sprint/focus', 'SprintController@getFocus');
Route::get('/sprint/list', 'SprintController@getList');
Route::get('/sprint/cig', 'SprintController@getCig');
Route::get('/sprint/junior/{juniorName?}', 'SprintController@getJunior');
Route::get('/sprint/reviews', 'ReviewController@getIndex');

Route::get('/gerrit', 'GerritController@getStatus');
Route::get('/gerrit/status', 'GerritController@getStatus');
Route::get('/gerrit/merge-ready', 'GerritController@getMergeReady');
Route::get('/gerrit/misses-verification', 'GerritController@getMissesVerification');
Route::get('/gerrit/misses-review', 'GerritController@getMissesReview');
Route::get('/gerrit/rebase', 'GerritController@getRebase');
Route::get('/gerrit/files', 'GerritController@getFiles');
Route::get('/gerrit/negative-vote-cleanup', 'GerritController@getNegativeVoteCleanup');
Route::get('/gerrit/wip', 'GerritController@getWorkInProgress');
Route::get('/gerrit/core-team-merge', 'GerritController@getCoreteamMerge');
Route::get('/gerrit/stale-issues', 'GerritController@getStaleIssues');

Route::get('/slack', 'SlackController@getIndex');

Route::get('/management', 'ManagementController@getIndex');

Route::get('/utility/rst', 'UtilityController@getRst');
Route::get('/utility/calendar', 'UtilityController@getCalendar');
Route::get('/utility/help', 'UtilityController@getHelp');
Route::get('/utility/issue', 'UtilityController@getIssue');

Route::get('/api/{type?}/{documentId?}', 'ApiController@index');

/**
 * Backpack routes
 */
// Admin Interface Routes
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'role:admin|junior_manager']], function()
{
    // Backpack\CRUD: Define the resources for the entities you want to CRUD.
    CRUD::resource('issue', 'Admin\IssueCrudController');
    CRUD::resource('junior', 'Admin\JuniorCrudController');

    // [...] other routes
});