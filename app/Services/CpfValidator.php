<?php

namespace App\Services;

/**
 * Validação de CPF por dígito verificador.
 *
 * Extraído de ProfileController para ser reaproveitado pela Declaração de
 * Emergência sem duplicar o algoritmo.
 */
class CpfValidator
{
    public function isValid(?string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', (string) $cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        // Rejeita sequências iguais (000.000.000-00, 111.111.111-11, etc.)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$t] != $d) {
                return false;
            }
        }

        return true;
    }
}
