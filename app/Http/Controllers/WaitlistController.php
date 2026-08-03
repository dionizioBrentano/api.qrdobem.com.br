<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WaitlistController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'interest' => 'required|string|in:grupo,empresa,doacoes,familia',
        ]);

        DB::table('waitlist_entries')->insertOrIgnore([
            'email' => $validated['email'],
            'interest' => $validated['interest'],
            'created_at' => Carbon::now(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
