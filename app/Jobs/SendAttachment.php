<?php

namespace App\Jobs;

use App\Mail\AttachmentFound;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class SendAttachment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $to;
    protected $subject;
    protected $body;
    protected $attachments = [];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($to, $subject, $body, $attachments)
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = Markdown::convertToHtml($body);
        $this->attachments = $attachments;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Mail::raw($this->body, function ($message) {
            $message->from('saleem@web-epoch.com', 'Saleem Beg');
            $message->subject($this->subject);
            foreach ($this->attachments as $attachment) {
                $message->attachData(base64_decode($attachment->attached_file->data), $attachment->attached_file->name);
            }
            $message->to($this->to);
        });
    }
}
