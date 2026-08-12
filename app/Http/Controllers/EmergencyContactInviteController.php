<?php

namespace App\Http\Controllers;

use App\Models\EmergencyContact;
use Illuminate\Http\Request;

class EmergencyContactInviteController extends Controller
{
    /**
     * Termo padro a ser exibido publicamente para o contato aceitar
     */
    private const CURRENT_TERM_VERSION = 'v1.0';
    private const TERM_TEXT = "Aceito ser um contato de emergncia. Compreendo que poderei receber alertas caso a pessoa/animal/objeto perca-se ou precise de socorro. Os alertas contaro localizao se houver. Meus dados de contato no sero exibidos ao pblico, mas o sistema me notificar via Push ou Email (caso configurado).";

    public function show($token)
    {
        $contact = EmergencyContact::where('invite_token', $token)->first();

        if (!$contact) {
            return response()->json(['error' => 'Convite invlido ou no encontrado.'], 404);
        }

        if ($contact->status === 'revoked') {
            return response()->json(['error' => 'Convite revogado pelo administrador.'], 403);
        }

        // Preview do nome apenas, sem telefones nem emails de ninguem
        return response()->json([
            'name_preview' => $contact->name,
            'status' => $contact->status,
            'term_version' => self::CURRENT_TERM_VERSION,
            'term_text' => self::TERM_TEXT,
        ]);
    }

    public function accept(Request $request, $token)
    {
        $contact = EmergencyContact::where('invite_token', $token)->first();

        if (!$contact || $contact->status === 'revoked') {
            return response()->json(['error' => 'Convite invlido ou revogado.'], 404);
        }

        $request->validate([
            'accept_term' => 'required|boolean|accepted'
        ]);

        if ($contact->status !== 'accepted') {
            $contact->update([
                'status' => 'accepted',
                'term_version' => self::CURRENT_TERM_VERSION,
                'term_accepted_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Termo aceito com sucesso.',
            'status' => $contact->status
        ]);
    }

    public function savePushSubscription(Request $request, $token)
    {
        $contact = EmergencyContact::where('invite_token', $token)->first();

        if (!$contact || $contact->status !== 'accepted') {
            return response()->json(['error' => 'Contato no encontrado ou ainda no aceito.'], 404);
        }

        $request->validate([
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $contact->update([
            'push_subscription' => [
                'endpoint' => $request->endpoint,
                'keys' => $request->keys,
            ]
        ]);

        return response()->json(['message' => 'Notificaes push ativadas com sucesso.']);
    }
}
