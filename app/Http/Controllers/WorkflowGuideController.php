<?php

namespace App\Http\Controllers;

use App\Services\WorkflowGuides;
use Illuminate\Http\Request;

class WorkflowGuideController extends Controller
{
    public function index(Request $request, WorkflowGuides $guides)
    {
        return view('guides.index', ['guides' => $guides->forUser($request->user())]);
    }
}
