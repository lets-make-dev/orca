<?php

namespace MakeDev\Orca\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class AutoLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if (! app()->isLocal() || ! config('orca.auto_login.enabled')) {
            abort(403, 'Auto-login is not available.');
        }

        $validated = $request->validate([
            'user' => 'required|integer|exists:users,id',
            'redirect' => 'required|string',
        ]);

        Auth::loginUsingId($validated['user']);

        return redirect($validated['redirect']);
    }
}
