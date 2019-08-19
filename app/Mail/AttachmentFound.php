<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class AttachmentFound extends Mailable
{
    use Queueable, SerializesModels;

    private $mailTo;
    private $mailSubject;
    // the values that shouldnt appear in the mail should be private

    public $attachment;

    /**
     * Create a new message instance.
     *
     * @param $to
     * @param $subject
     * @param $attachment
     */
    public function __construct($to, $subject, $attachment)
    {
        $this->mailSubject = $to;
        $this->mailSubject = $subject;
        $this->attachment = $attachment;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $this->view('emails.raw');
        if ($this->attachment != null)
            $this->attachData(base64_decode($this->attachment->attached_file->data), $this->attachment->attached_file->name);
        $this->subject($this->mailSubject)
            ->to($this->mailTo);
    }
}
