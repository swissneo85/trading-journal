<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class LogController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 100);

        return response()->json(
            ActivityLog::orderByDesc('created_at')->limit($limit)->get()
        );
    }
}
