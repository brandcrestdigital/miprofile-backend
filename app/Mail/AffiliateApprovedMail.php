<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;


use App\Models\Affiliate;


class AffiliateApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $affiliate;
    public $password;
    public $referralUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(
        Affiliate $affiliate,
        string $password,
        string $referralUrl
    ) {
        $this->affiliate = $affiliate;
        $this->password = $password;
        $this->referralUrl = $referralUrl;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Affiliate Account Has Been Approved',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.affiliate-approved',
        );
    }


    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
