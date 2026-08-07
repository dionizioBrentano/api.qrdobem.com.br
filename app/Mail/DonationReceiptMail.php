<?php

namespace App\Mail;

use App\Models\Donation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * DonationReceiptMail — comprovante/recibo da doação confirmada.
 *
 * Enviado quando a doação vira `paid` (DonationController::markAsPaid), tanto
 * para doador logado quanto para guest. Discrimina o rateio: bruto, taxa de
 * 12% da OSCIP, custo do meio de pagamento (quando conhecido) e o líquido
 * destinado à causa.
 *
 * NÃO promete redução de IRPF — a modalidade ativa é doação com recibo da
 * OSCIP, sem projeto de incentivo homologado.
 */
class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Donation $donation)
    {
    }

    public function build()
    {
        return $this->subject('Recibo da sua doação — QR do Bem')
                    ->view('emails.donation-receipt', [
                        'donation' => $this->donation,
                        'causeName' => $this->donation->cause?->name,
                    ]);
    }
}
