<?php

namespace App\Services;

/**
 * TotpService — TOTP (RFC 6238) sem dependência externa.
 * Fase 1, entrega 1.5 do PLANO_TRILHAS_2026-08.md (T1-R05).
 *
 * POR QUE SEM BIBLIOTECA
 * O servidor é hospedagem compartilhada e o `composer require` no CPanel é
 * ponto recorrente de dor. O algoritmo do TOTP é HMAC-SHA1 sobre o contador
 * de tempo — cabe em pouco código, é padrão fechado e não muda. Trazer
 * dependência para isso seria mais risco de deploy do que benefício.
 *
 * Compatível com Google Authenticator, Authy e Microsoft Authenticator:
 * SHA1, 6 dígitos, janela de 30 s — os padrões que esses apps assumem.
 */
class TotpService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // Base32, RFC 4648

    /**
     * Gera um segredo novo em Base32 (160 bits, como recomenda a RFC).
     */
    public function generateSecret(int $bytes = 20): string
    {
        $random = random_bytes($bytes);

        return $this->base32Encode($random);
    }

    /**
     * URI otpauth:// para o QR Code do app autenticador.
     * O usuário também pode digitar o segredo à mão, então ele é exibido.
     */
    public function provisioningUri(string $secret, string $account, string $issuer = 'QR do Bem'): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /**
     * Código esperado para um instante.
     */
    public function codeAt(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);

        $binaryCounter = pack('N*', 0, $counter); // 64 bits big-endian
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($secret), true);

        // Truncamento dinâmico (RFC 4226, seção 5.4)
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);

        return str_pad((string) $value, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica um código aceitando desvio de relógio.
     *
     * `window = 1` aceita o código anterior e o seguinte (±30 s). Sem essa
     * tolerância, celular com relógio levemente adiantado falha sempre — e
     * o usuário conclui que o 2FA está quebrado.
     *
     * A comparação usa hash_equals: comparação de string comum vaza
     * informação por tempo de execução.
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return false;
        }

        $now = time();

        for ($i = -$window; $i <= $window; $i++) {
            $candidate = $this->codeAt($secret, $now + ($i * self::PERIOD));

            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Códigos de recuperação, para quem perde o celular.
     * Devolvidos em claro UMA vez; o banco guarda só o hash.
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    private function base32Encode(string $data): string
    {
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($binary, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $secret = rtrim(strtoupper($secret), '=');
        $binary = '';

        foreach (str_split($secret) as $char) {
            $position = strpos(self::ALPHABET, $char);

            if ($position === false) {
                continue; // caractere inválido é ignorado, não derruba
            }

            $binary .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $decoded .= chr(bindec($byte));
            }
        }

        return $decoded;
    }
}
