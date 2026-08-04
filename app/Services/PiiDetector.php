<?php

namespace App\Services;

class PiiDetector
{
    /**
     * Mensagem de risco devolvida ao cliente quando um contato direto é detectado.
     */
    public const CONTACT_MESSAGE = 'Sua mensagem parece conter um contato pessoal (telefone ou e-mail). Por segurança, o QR do Bem não permite troca direta de contato. Se tiver certeza que deseja enviar mesmo assim, confirme.';

    /**
     * Telefone brasileiro nos formatos usados na prática:
     * (11) 91234-5678, 11912345678, 11 91234 5678, 11-91234-5678.
     */
    private const PHONE_REGEX = '/(?:\(?\d{2}\)?[\s.-]?)?9?\d{4}[\s.-]?\d{4}/';

    private const EMAIL_REGEX = '/[\w.+-]+@[\w-]+\.[\w.-]+/';

    /**
     * Indica se o texto contém telefone ou e-mail.
     */
    public function containsContact(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return preg_match(self::EMAIL_REGEX, $text) === 1
            || preg_match(self::PHONE_REGEX, $text) === 1;
    }
}
