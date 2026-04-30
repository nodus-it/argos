<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AutoLoginMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            $user = User::firstOrCreate(
                ['email' => 'admin@argos.local'],
                [
                    'name'     => 'Argos Admin',
                    'password' => Hash::make('local'),
                ],
            );

            Auth::login($user);
        }

        return $next($request);
    }
}
