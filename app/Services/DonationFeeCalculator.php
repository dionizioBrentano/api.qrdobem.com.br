<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * DonationFeeCalculator — cálculo canônico do rateio de uma doação.
 *
 * Serviço PURO e sem estado: não toca no banco, não chama gateway, não
 * depende de request. É a ÚNICA fonte de verdade do rateio, para que o
 * preview (front), a criação da doação (controller) e qualquer conciliação
 * futura cheguem exatamente ao mesmo número. Duplicar essa conta em dois
 * lugares é como se produz doação creditada errado.
 *
 * REGRA FISCAL (07/08/2026)
 * Única modalidade ativa: doação com recibo da OSCIP gestora. NÃO há projeto
 * de incentivo homologado — portanto NENHUM benefício de IRPF é calculado ou
 * exibido aqui. Só existem:
 *   1. taxa da plataforma/OSCIP — percentual sobre o VALOR BRUTO;
 *   2. custo do meio de pagamento — valor real, à parte, discriminado;
 *   3. apoio extra opcional — valor adicional só para a OSCIP/plataforma,
 *      que NÃO se mistura com a taxa dos itens acima.
 *
 * DINHEIRO EM REAIS COM 2 CASAS
 * Segue o padrão do projeto (CreditOrder, Donation.amount = decimal:2): reais
 * como float arredondado a 2 casas com round(), não centavos inteiros. Todo
 * valor que sai daqui já vem arredondado.
 */
class DonationFeeCalculator
{
    /** Taxa padrão da plataforma (%), vinda da config. */
    public function feePercent(): float
    {
        return (float) config('qrdobem.donation.platform_fee_percent', 12);
    }

    /**
     * Detalha o rateio de uma doação.
     *
     * @param  float       $amountGross           Valor bruto da doação (o "quanto quero doar").
     * @param  bool        $coverFees             Se true, o doador cobre as taxas (a causa recebe o bruto).
     * @param  float       $paymentFee            Custo real do meio de pagamento. 0 quando ainda não cobrado.
     * @param  float       $extraPlatformSupport  Apoio extra opcional, só para a OSCIP/plataforma.
     * @param  float|null  $feePercent            Sobrescreve a taxa da config (usado em testes).
     * @return array{
     *     amount_gross: float, platform_fee_percent: float, platform_fee_amount: float,
     *     payment_fee_amount: float, extra_platform_support: float, cover_fees: bool,
     *     amount_to_cause: float, total_to_pay: float, lines: array<int, array{label: string, amount: float}>
     * }
     *
     * @throws InvalidArgumentException  Entrada inválida, ou se as taxas zerariam/estourariam a causa.
     */
    public function breakdown(
        float $amountGross,
        bool $coverFees = false,
        float $paymentFee = 0.0,
        float $extraPlatformSupport = 0.0,
        ?float $feePercent = null
    ): array {
        $feePercent = $feePercent ?? $this->feePercent();

        if ($amountGross <= 0) {
            throw new InvalidArgumentException('O valor da doação precisa ser maior que zero.');
        }
        if ($paymentFee < 0) {
            throw new InvalidArgumentException('O custo do meio de pagamento não pode ser negativo.');
        }
        if ($extraPlatformSupport < 0) {
            throw new InvalidArgumentException('O apoio extra à plataforma não pode ser negativo.');
        }
        if ($feePercent < 0) {
            throw new InvalidArgumentException('A taxa da plataforma não pode ser negativa.');
        }

        $amountGross = round($amountGross, 2);
        $paymentFee  = round($paymentFee, 2);
        $extra       = round($extraPlatformSupport, 2);

        // Taxa da plataforma SEMPRE sobre o bruto — nunca sobre o líquido nem
        // sobre o total com meio de pagamento.
        $platformFee = round($amountGross * $feePercent / 100, 2);

        if ($coverFees) {
            // O doador banca as taxas: a causa recebe o bruto e o total a
            // pagar sobe pelo tanto das taxas + apoio extra.
            $amountToCause = $amountGross;
            $totalToPay    = round($amountGross + $platformFee + $paymentFee + $extra, 2);
        } else {
            // As taxas saem de dentro da doação: a causa recebe o líquido e o
            // doador paga o bruto (+ apoio extra, que nunca reduz a causa).
            $amountToCause = round($amountGross - $platformFee - $paymentFee, 2);
            $totalToPay    = round($amountGross + $extra, 2);
        }

        // PARE: se as taxas superam a doação, nada chegaria à causa. Repassar
        // valor negativo é impossível — e mascarar com zero esconderia o
        // problema. Erro claro, para o chamador corrigir a entrada.
        if ($amountToCause < 0) {
            throw new InvalidArgumentException(
                'As taxas superam o valor da doação: nada chegaria à causa. '
                . 'Revise o valor doado ou marque a opção de cobrir as taxas.'
            );
        }

        $lines = [
            ['label' => 'Doação', 'amount' => $amountGross],
            ['label' => "Taxa da plataforma ({$this->formatPercent($feePercent)}%)", 'amount' => $platformFee],
        ];

        if ($paymentFee > 0) {
            $lines[] = ['label' => 'Custo do meio de pagamento', 'amount' => $paymentFee];
        }
        if ($extra > 0) {
            $lines[] = ['label' => 'Apoio extra à plataforma', 'amount' => $extra];
        }

        $lines[] = ['label' => 'Valor que chega à causa', 'amount' => $amountToCause];
        $lines[] = ['label' => 'Total a pagar', 'amount' => $totalToPay];

        return [
            'amount_gross'           => $amountGross,
            'platform_fee_percent'   => round($feePercent, 2),
            'platform_fee_amount'    => $platformFee,
            'payment_fee_amount'     => $paymentFee,
            'extra_platform_support' => $extra,
            'cover_fees'             => $coverFees,
            'amount_to_cause'        => $amountToCause,
            'total_to_pay'           => $totalToPay,
            'lines'                  => $lines,
        ];
    }

    /**
     * Formata o percentual para o rótulo, em pt-BR e sem casas inúteis:
     * 12 → "12", 12.5 → "12,5".
     */
    private function formatPercent(float $percent): string
    {
        if (floor($percent) === $percent) {
            return (string) (int) $percent;
        }

        return rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
    }
}
