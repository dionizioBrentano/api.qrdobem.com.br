<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\RegistrationToken;
use App\Models\Tenant;
use App\Mail\RegistrationLinkMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class RegisterController extends Controller
{
    public function sendLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;

        // Se já existir Tenant com esse email E profile_status active
        $tenant = Tenant::where('email', $email)->first();
        if ($tenant && $tenant->profile_status === 'active') {
            return response()->json(['message' => 'Se o e-mail estiver disponível, enviamos o link.']);
        }

        // Cria token (random 32 bytes) e salva apenas o hash
        $tokenPlain = Str::random(32);
        $tokenHash = hash('sha256', $tokenPlain);

        RegistrationToken::create([
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => Carbon::now()->addHours(24)
        ]);

        $frontendUrl = env('FRONTEND_URL', 'https://app.qrdobem.com.br');
        $link = "{$frontendUrl}/register?token={$tokenPlain}";

        try {
            Mail::to($email)->send(new RegistrationLinkMail($link));
        } catch (\Exception $e) {
            // Falha silenciosa para evitar enumeration, mas pode ser logada
        }

        return response()->json(['message' => 'Se o e-mail estiver disponível, enviamos o link.']);
    }

    public function complete(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            // id_token é validado automaticamente pelo middleware auth.firebase
        ]);

        $tokenPlain = $request->token;
        $tokenHash = hash('sha256', $tokenPlain);

        $registrationToken = RegistrationToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$registrationToken) {
            return response()->json(['error' => 'Token inválido ou expirado.'], 400);
        }

        // Recupera o tenant autenticado via auth.firebase
        $tenant = $request->tenant;

        if (!$tenant || strtolower($tenant->email) !== strtolower($registrationToken->email)) {
            return response()->json(['error' => 'Usuário autenticado não corresponde ao token.'], 403);
        }

        // Marca o token como usado
        $registrationToken->update(['used_at' => Carbon::now()]);

        // Marca email como verificado se ainda não estiver
        if (!$tenant->email_verified_at) {
            $tenant->update(['email_verified_at' => Carbon::now()]);
        }

        return response()->json([
            'email' => $tenant->email,
            'registration_token_ok' => true
        ]);
    }

    public function validateToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $tokenHash = hash('sha256', $request->token);
        $registrationToken = RegistrationToken::where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$registrationToken) {
            return response()->json(['error' => 'Token inválido ou expirado.'], 400);
        }

        return response()->json(['email' => $registrationToken->email]);
    }
}
