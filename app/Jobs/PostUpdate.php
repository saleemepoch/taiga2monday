<?php

namespace App\Jobs;

use App\Http\Controllers\MigrationController;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class PostUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $item_id;
    protected $comments = [];
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($item_id, $comments)
    {
        $this->item_id = $item_id;
        $this->comments = array_reverse($comments);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('PostUpdate Job');
        $migration_controller = new MigrationController;
        foreach ($this->comments as $comment) {
            $user = '<strong>'.$comment->data->user->email.':</strong><br>';
            $response = $migration_controller->monday('mutation {
            create_update (item_id: '.$this->item_id.', body: "'.$user.addcslashes($comment->data->comment_html, '"').'") {
            id
            }
        }');
        }
    }
}
