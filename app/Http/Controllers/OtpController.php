<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\OtpCode;
use App\Models\Tenant;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class OtpController extends Controller
{
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'firebase_uid' => 'required|string'
        ]);

        $email = $request->email;
        $uid = $request->firebase_uid;

        // Invalida códigos antigos
        OtpCode::where('firebase_uid', $uid)->update(['is_used' => true]);

        // Gera 6 dígitos numéricos
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'firebase_uid' => $uid,
            'email' => $email,
            'code' => $code,
            'expires_at' => Carbon::now()->addMinutes(15)
        ]);

        try {
            Mail::to($email)->send(new VerificationCodeMail($code));
        } catch (\Exception $e) {
            return response()->json(['error' => 'Falha ao enviar e-mail. Verifique o SMTP.'], 500);
        }

        return response()->json(['message' => 'Código enviado com sucesso.']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'firebase_uid' => 'required|string',
            'code' => 'required|string|size:6'
        ]);

        $uid = $request->firebase_uid;
        $code = $request->code;

        $otp = OtpCode::where('firebase_uid', $uid)
            ->where('code', $code)
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otp) {
            return response()->json(['error' => 'Código inválido ou expirado.'], 400);
        }

        // Marca como usado
        $otp->update(['is_used' => true]);

        // Marca o Tenant como verificado (se ele já existir no banco, 
        // caso o fluxo de criação do Tenant seja no Onboarding ou via FirebaseAuth)
        // Se o Tenant ainda não existe, nós podemos criar ou simplesmente confiar no firebase_uid futuramente.
        // O FirebaseAuth.php já cadastra o Tenant no primeiro request dele.
        $tenant = Tenant::where('firebase_uid', $uid)->first();
        if ($tenant) {
            $tenant->update(['email_verified_at' => now()]);

            // Verifica se agora cumpre todos os requisitos para ativação
            // Gate 1: email verificado + CPF + telefone
            $hasCpf = !empty($tenant->cpf);
            $hasPhone = !empty($tenant->phone);
            if ($hasCpf && $hasPhone) {
                $tenant->update(['profile_status' => 'active']);
                
                // Concede créditos de onboarding ao atingir o status active
                app(\App\Services\OnboardingCreditService::class)->grantOnboardingBatch($tenant);
            }
        }

        return response()->json([
            'message' => 'E-mail verificado com sucesso.',
            'profile_status' => $tenant ? $tenant->fresh()->profile_status : 'incomplete',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['tenant' => $request->tenant]);
    }
}
