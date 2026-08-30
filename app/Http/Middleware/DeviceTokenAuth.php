<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\EntityDevice;
use Illuminate\Support\Facades\Hash;

class DeviceTokenAuth
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('X-Device-Token');

        if (!$token) {
            return response()->json(['error' => 'Token do dispositivo não fornecido.'], 401);
        }

        $hash = hash('sha256', $token);

        $device = EntityDevice::where('token_hash', $hash)
            ->where(function ($query) {
                $query->whereNull('token_expires_at')
                      ->orWhere('token_expires_at', '>', now());
            })
            ->first();

        if (!$device) {
            return response()->json(['error' => 'Token do dispositivo inválido ou expirado.'], 401);
        }

        $request->attributes->add([
            'device' => $device,
            'entity' => $device->entity
        ]);

        return $next($request);
    }
}
