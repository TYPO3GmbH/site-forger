<?php namespace App\Http\Controllers;

class SearchController extends Controller {

	/**
	 * Create a new controller instance.
	 */
	public function __construct()  {
		parent::__construct();
		$this->middleware('guest');
	}

	/**
	 * Show the index screen to the user.
	 *
	 * @return Response
	 */
	public function index() {
		return view('templates.search.index');
	}

}
