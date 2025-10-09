<?php

namespace App\Http\Controllers\User;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use App\Models\User;
use App\Http\Controllers\Controller;

class WebhookController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.webhook');
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request)
    {
        $webhook_url = $request->input('webhook_url');
        $webhook_endpoint = $request->input('webhook_endpoint');

        $user = User::where('id', auth()->id())->first();
        //dd($user);
        $user->update(compact('webhook_url', 'webhook_endpoint'));

        return back()->with('success', 'Webhooks alterado com sucesso');
    }
}
