<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailVerificationController extends Controller
{
    public function show()
    {
        return view('auth.verify');
    }

    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();

        if (
            $user->email_verification_code !== $request->code ||
            now()->gt($user->email_verification_expires_at)
        ) {
            return back()->withErrors(['code' => 'Invalid or expired code']);
        }

        $user->update([
            'email_verified_at' => now(),
            'email_verification_code' => null,
            'email_verification_expires_at' => null,
        ]);

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}

