<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewConversationMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public $entityName;
    public $link;

    public function __construct($entityName, $link)
    {
        $this->entityName = $entityName;
        $this->link = $link;
    }

    public function build()
    {
        return $this->subject('Nova mensagem sobre seu QR Code - QR do Bem')
                    ->view('emails.new-conversation-message');
    }
}
