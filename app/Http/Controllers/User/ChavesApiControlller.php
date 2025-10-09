<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\UsersKey;
use App\Models\User;
class ChavesApiControlller extends Controller
{
    public function index()
    {
        $chaveApi = UsersKey::where("user_id", auth()->user()->user_id)->first();
        if(!$chaveApi){
            $token = Str::uuid()->toString();
            $secret = Str::uuid()->toString();
            UsersKey::create([
                'user_id' => auth()->user()->user_id,
                'token' => $token,
                'secret' => $secret,
                'status' => 1
            ]);

            User::where('id', auth()->user()->id)->update(['cliente_id' => $token ]);

        } else {
            if(!$chaveApi->token){
                $token = Str::uuid()->toString();
                $chaveApi->token = $token;
                $chaveApi->save();
            } 
            
            if(!$chaveApi->secret){
                $secret = Str::uuid()->toString();
                $chaveApi->secret = $secret;
                $chaveApi->save();
            } 
            
            $token = $chaveApi->token;
            $secret = $chaveApi->secret;
        }


        return view("profile.chavesapi",compact('token','secret'));
    }
}