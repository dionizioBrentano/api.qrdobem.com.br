<?php

namespace App\Models;

/**
 * @deprecated Renomeado para App\Models\DonationCause (bounded context de
 * pagamento a causa). Este alias existe só para não quebrar referências
 * remanescentes durante a separação dos agregados de doação; aponta para a
 * MESMA tabela (donation_causes) por herança.
 *
 * REMOVER MANUALMENTE este arquivo depois de confirmar que nada mais usa
 * `App\Models\Donation` (a remoção não foi feita aqui porque o fluxo do
 * projeto é só escrita de arquivos, sem shell).
 */
class Donation extends DonationCause
{
}
