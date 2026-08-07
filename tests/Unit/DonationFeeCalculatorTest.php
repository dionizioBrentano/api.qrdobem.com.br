<?php

namespace Tests\Unit;

use App\Services\DonationFeeCalculator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cobre o rateio canônico da doação. O serviço é puro, então cada caso é a
 * conta inteira: entra o bruto e as opções, sai o rateio fechado.
 *
 * A taxa é passada explícita (12) em cada caso para não depender da config —
 * o objetivo é travar a MATEMÁTICA do rateio, não o valor default.
 */
class DonationFeeCalculatorTest extends TestCase
{
    private function calc(): DonationFeeCalculator
    {
        return new DonationFeeCalculator();
    }

    public function test_taxa_padrao_sai_da_config(): void
    {
        config(['qrdobem.donation.platform_fee_percent' => 12]);

        $this->assertSame(12.0, $this->calc()->feePercent());
    }

    public function test_rateio_simples_desconta_taxa_do_bruto(): void
    {
        // R$ 100, taxa 12%, sem cobrir taxas: causa recebe R$ 88.
        $b = $this->calc()->breakdown(100.0, false, 0.0, 0.0, 12);

        $this->assertSame(100.0, $b['amount_gross']);
        $this->assertSame(12.0, $b['platform_fee_amount']);
        $this->assertSame(0.0, $b['payment_fee_amount']);
        $this->assertSame(88.0, $b['amount_to_cause']);
        $this->assertSame(100.0, $b['total_to_pay']);
        $this->assertFalse($b['cover_fees']);
    }

    public function test_cover_fees_faz_causa_receber_o_bruto(): void
    {
        // Doador cobre a taxa: causa recebe os R$ 100 e ele paga R$ 112.
        $b = $this->calc()->breakdown(100.0, true, 0.0, 0.0, 12);

        $this->assertSame(100.0, $b['amount_to_cause']);
        $this->assertSame(112.0, $b['total_to_pay']);
        $this->assertTrue($b['cover_fees']);
    }

    public function test_custo_do_meio_de_pagamento_sai_do_liquido(): void
    {
        // Taxa 12% (R$ 12) + gateway R$ 3: causa recebe 100 - 12 - 3 = 85.
        $b = $this->calc()->breakdown(100.0, false, 3.0, 0.0, 12);

        $this->assertSame(3.0, $b['payment_fee_amount']);
        $this->assertSame(85.0, $b['amount_to_cause']);
        $this->assertSame(100.0, $b['total_to_pay']);
    }

    public function test_cover_fees_soma_gateway_ao_total(): void
    {
        // Cobrindo tudo: paga 100 + 12 + 3 = 115 e causa recebe 100.
        $b = $this->calc()->breakdown(100.0, true, 3.0, 0.0, 12);

        $this->assertSame(100.0, $b['amount_to_cause']);
        $this->assertSame(115.0, $b['total_to_pay']);
    }

    public function test_apoio_extra_nao_entra_na_taxa_nem_reduz_a_causa(): void
    {
        // R$ 10 de apoio extra: só engorda o total, a taxa segue sobre o bruto
        // e a causa recebe o líquido do bruto (não descontam o apoio).
        $b = $this->calc()->breakdown(100.0, false, 0.0, 10.0, 12);

        $this->assertSame(12.0, $b['platform_fee_amount']);
        $this->assertSame(10.0, $b['extra_platform_support']);
        $this->assertSame(88.0, $b['amount_to_cause']);
        $this->assertSame(110.0, $b['total_to_pay']);
    }

    public function test_arredonda_taxa_para_duas_casas(): void
    {
        // 33.33 * 12% = 3.9996 -> 4.00; causa = 33.33 - 4.00 = 29.33.
        $b = $this->calc()->breakdown(33.33, false, 0.0, 0.0, 12);

        $this->assertSame(4.0, $b['platform_fee_amount']);
        $this->assertSame(29.33, $b['amount_to_cause']);
    }

    public function test_lines_incluem_taxa_e_total_e_ocultam_zeros(): void
    {
        $b = $this->calc()->breakdown(100.0, false, 0.0, 0.0, 12);
        $labels = array_column($b['lines'], 'label');

        $this->assertContains('Doação', $labels);
        $this->assertContains('Taxa da plataforma (12%)', $labels);
        $this->assertContains('Valor que chega à causa', $labels);
        $this->assertContains('Total a pagar', $labels);
        // Sem gateway nem apoio, essas linhas não aparecem.
        $this->assertNotContains('Custo do meio de pagamento', $labels);
        $this->assertNotContains('Apoio extra à plataforma', $labels);
    }

    public function test_erro_quando_taxas_estouram_a_doacao(): void
    {
        // Gateway maior que o que sobra após a taxa: nada chegaria à causa.
        $this->expectException(InvalidArgumentException::class);

        $this->calc()->breakdown(10.0, false, 20.0, 0.0, 12);
    }

    public function test_erro_quando_valor_nao_e_positivo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc()->breakdown(0.0, false, 0.0, 0.0, 12);
    }

    public function test_erro_quando_apoio_extra_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calc()->breakdown(100.0, false, 0.0, -1.0, 12);
    }
}
