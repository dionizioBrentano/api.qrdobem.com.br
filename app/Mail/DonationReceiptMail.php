<?php

namespace App\Mail;

use App\Models\DonationCause;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * DonationReceiptMail — comprovante/recibo da DOAÇÃO A CAUSA confirmada.
 *
 * É o recibo por e-mail do checkout (model DonationCause) — não confundir com
 * o legado DonationReceipt (comprovante manual ligado a need). Enviado quando
 * a doação vira `paid` (DonationCauseController::markAsPaid), tanto para
 * doador logado quanto para guest. Discrimina o rateio: bruto, taxa de 12% da
 * OSCIP, custo do meio de pagamento (quando conhecido) e o líquido à causa.
 *
 * NÃO promete redução de IRPF — a modalidade ativa é doação com recibo da
 * OSCIP, sem projeto de incentivo homologado.
 */
class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DonationCause $donation)
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
