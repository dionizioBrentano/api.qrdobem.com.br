@php
    // Formatação de moeda em reais, sem depender de locale do servidor.
    $money = fn ($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
    $feePercent = rtrim(rtrim(number_format((float) $donation->platform_fee_percent, 2, ',', ''), '0'), ',');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Recibo da sua doação</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h2 style="color: #333333; text-align: center;">Obrigado pela sua doação!</h2>

        <p style="color: #666666; font-size: 16px; line-height: 1.5;">
            Olá{{ $donation->donor_name ? ', ' . $donation->donor_name : '' }}. Recebemos e confirmamos a sua doação
            @if($causeName)
                para a causa <strong>{{ $causeName }}</strong>.
            @else
                para onde for mais necessário.
            @endif
        </p>

        <table style="width: 100%; border-collapse: collapse; margin: 24px 0; font-size: 15px; color: #444444;">
            <tr>
                <td style="padding: 8px 0;">Valor da doação</td>
                <td style="padding: 8px 0; text-align: right;">{{ $money($donation->amount_gross ?? $donation->amount) }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;">Taxa operacional QR do Bem / OSCIP ({{ $feePercent }}%)</td>
                <td style="padding: 8px 0; text-align: right;">{{ $money($donation->platform_fee_amount) }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0;">Meio de pagamento</td>
                <td style="padding: 8px 0; text-align: right;">
                    {{ (float) $donation->payment_fee_amount > 0 ? $money($donation->payment_fee_amount) : 'conforme a operadora' }}
                </td>
            </tr>
            @if((float) $donation->extra_platform_support > 0)
            <tr>
                <td style="padding: 8px 0;">Apoio voluntário ao QR do Bem</td>
                <td style="padding: 8px 0; text-align: right;">{{ $money($donation->extra_platform_support) }}</td>
            </tr>
            @endif
            <tr>
                <td style="padding: 12px 0; border-top: 1px solid #eeeeee; font-weight: bold; color: #1a73e8;">Destinado a esta causa</td>
                <td style="padding: 12px 0; border-top: 1px solid #eeeeee; text-align: right; font-weight: bold; color: #1a73e8;">{{ $money($donation->amount_to_cause ?? $donation->amount) }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold;">Total pago</td>
                <td style="padding: 8px 0; text-align: right; font-weight: bold;">{{ $money($donation->amount) }}</td>
            </tr>
        </table>

        <p style="color: #666666; font-size: 14px; line-height: 1.5;">
            O valor vai para a OSCIP gestora do QR do Bem, que faz a distribuição e responde pela prestação de contas.
            Esta é uma doação solidária com recibo da OSCIP — ela não reduz o Imposto de Renda como incentivos da Lei
            Rouanet, do Fundo da Criança (FIA) ou do Esporte, que exigem projeto homologado.
        </p>

        @if($donation->public_token)
            <div style="text-align: center; margin-top: 30px;">
                <a href="{{ url('/doacao/status/' . $donation->public_token) }}" style="display: inline-block; background-color: #1a73e8; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold;">
                    Acompanhar Status da Doação
                </a>
            </div>
        @endif

        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;" />
        <p style="color: #999999; font-size: 12px; text-align: center;">Equipe QR do Bem</p>
    </div>
</body>
</html>
