<?php

namespace App\Http\Controllers;

/**
 * @deprecated Renomeado para App\Http\Controllers\DonationCauseController
 * (bounded context de pagamento a causa). Toda a lógica de checkout — preview,
 * store (guest/auth opcional), mine, subscribe, publicList e o markAsPaid do
 * webhook — vive lá. Este alias herda tudo só para não quebrar referências
 * remanescentes durante a separação dos agregados de doação.
 *
 * REMOVER MANUALMENTE este arquivo depois de confirmar que nada mais usa
 * `App\Http\Controllers\DonationController` (as rotas e o WebhookController já
 * foram atualizados para DonationCauseController). A remoção não foi feita
 * aqui porque o fluxo do projeto é só escrita de arquivos, sem shell.
 */
class DonationController extends DonationCauseController
{
}
