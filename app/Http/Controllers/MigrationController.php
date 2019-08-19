<?php

namespace App\Http\Controllers;

use App\Jobs\PostUpdate;
use App\Jobs\SendAttachment;
use App\Mail\AttachmentFound;
use Carbon\Carbon;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Zttp\Zttp;

class MigrationController extends Controller
{

    protected $sprint_board_id = 298701439;
    protected $backlog_board_id = 298701842;
    protected $taiga_file = 'my-taiga-project-dd363f63494e4582861e770089862f27.json';
    public $columns = [];
    public $users = [];
    public $comments = [];
    public $groups = [];
    protected $timeline = [];
    public $interval = 10;
    public $dispatch_time = null;

    /**
     * US Statuses in Monday
     *
     * 0 - Working on it
     * 1 - Done
     * 2 - Stuck
     * 3 - Waiting for Deployment
     * 4 - Ongoing
     * 5 - {blank}
     * 6 - On hold
     * 7 - Waiting for review
     */
    public $monday_status = [
        'New' => 5,
        'Ready' => 5,
        'In progress' => 0,
        'Ready for test' => 7,
        'Done' => 1,
        'Archived' => 6
    ];

    /**
     * The function to trigger all the magic
     *
     */
    public function migrate() {

        $json = \Storage::get($this->taiga_file);
        $contents = json_decode($json);

        // set users
        $this->setUsers();

        // set timeline
        $this->timeline = $contents->timeline;

        $this->dispatch_time = now();

        // migrate all sprints
        $this->migrateSprints($contents, $this->sprint_board_id);

        // migrate backlog
        $this->migrateBacklog($contents, $this->backlog_board_id);
    }

    /**
     * Short Monday.com API wrapper
     *
     * @param $query
     * @return mixed
     */
    public function monday($query) {
        $response = Zttp::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization:' => env("MONDAY_API"),

        ])->post('https://api.monday.com/v2/', [
            'query' => $query
        ]);

        return $response->json();
    }

    /**
     * Get all users from Monday.com
     */
    public function setUsers() {
        $users = $this->monday('{users (kind: non_guests) {id email}}');
        foreach ($users['data']['users'] as $user) {
            $this->users[$user['email']] = $user['id'];
        }
    }

    /**
     * Set all comments for any given Taiga User Story
     *
     * These comments are then dispatched off via Monday.com for that User Story
     *
     * @param $timeline
     * @param $user_story
     */
    private function setComments($timeline, $user_story) {
        $this->comments = [];
        $out = [];
        foreach ($timeline as $item) {
            if (isset($item->data->comment) && $item->data->comment != null && !isset($item->data->task)
                && (isset($item->data->userstory->subject) && $item->data->userstory->subject == $user_story)
                && !isset($item->data->comment_deleted) && !isset($item->data->comment_edited)
            ) {
                $this->comments[] = $item;
            }
        }
    }

    /**
     * Get all columns from a given Monday.com board
     *
     * @param $board_id
     * @return array
     */
    public function getBoardColumns($board_id) {
        $columns = $this->monday('{boards (ids: '.$board_id.') {owner{id} columns {id title type}}}');

        $out=[];
        foreach ($columns['data']['boards'][0]['columns'] as $column) {
            $out[$column['title']] = $column['id'];
        }

        return $out;
    }

    /**
     * Take a Taiga User Story, prepare it for Monday.com and post it as an item under a given group
     *
     * @param $board_id
     * @param $group_id
     * @param $user_story
     * @return mixed
     */
    public function createUS($board_id, $group_id, $user_story) {

        // prepare columns for the user story
        $item_name = $user_story->subject;

        // Set Item status
        $status = ($user_story->is_closed) ? 1 : $this->monday_status[$user_story->status];
        $column_queries[] = '\"'.$this->columns['Status'].'\": {\"index\": '.$status.'}';

        // Set the person the item should be assigned to
        if (isset($this->users[$user_story->assigned_to]))
            $column_queries[] = '\"'.$this->columns['Assignee'].'\": {\"personsAndTeams\":[{\"id\":'.$this->users[$user_story->assigned_to].', \"kind\":\"person\"}]}';

        // Work out User Story points and prepare Points estimation column
        $estimation_column_name = $this->columns['Estimation'];
        $estimation_column_value = null;
        foreach ($user_story->role_points as $role_point) {
            if ($role_point->points !== '?')
                $estimation_column_value = $estimation_column_value + $role_point->points;
        }

        if ($estimation_column_value != null)
            $column_queries[]= '\"'.$estimation_column_name.'\": '.$estimation_column_value;

        // prepare and submit item creation query
        $query = 'mutation {
            create_item (board_id: '.$board_id.', group_id: "'.$group_id.'", item_name: "'.addcslashes($item_name, '"').'", 
            column_values: "{
                '.implode(', ', $column_queries).'
                }") {
                    id
                }
            }';

        $new_item = $this->monday($query);

        // if there is an error with the creation of an item, we would like to dd() here and see what caused the error
        if (!isset( $new_item['data']))
            dd($new_item, $query);

        $item_id = $new_item['data']['create_item']['id'];

        //set comments for this US
        $this->setComments($this->timeline, $item_name);

        // In Monday.com create an item update for user story description with or without attachments via email
        if ($user_story->description != null || count($user_story->attachments) > 0) {
            $this->dispatch_time = $this->dispatch_time->copy()->addSeconds($this->interval);
            $this->attachment($item_id, $user_story);
        }

        return $new_item;
    }

    /**
     * Create all user stories in Taiga that do not belong to a sprint as items in the backlog board
     *
     * @param $board_id
     * @param $group_id
     * @param $user_story
     * @return mixed
     */
    public function createBacklog($board_id, $group_id, $user_story) {

        // prepare columns for the user story
        $item_name = $user_story->subject;

        $column_queries[] = '\"'.$this->columns['Status'].'\": {\"index\": '.$this->monday_status[$user_story->status].'}';

        if (isset($this->users[$user_story->assigned_to]))
            $column_queries[] = '\"'.$this->columns['Owner'].'\": {\"personsAndTeams\":[{\"id\":'.$this->users[$user_story->assigned_to].', \"kind\":\"person\"}]}';

        $estimation_column_name = $this->columns['Estimation'];
        $estimation_column_value = null;
        foreach ($user_story->role_points as $role_point) {
            if ($role_point->points !== '?')
                $estimation_column_value = $estimation_column_value + $role_point->points;
        }

        if ($estimation_column_value != null)
            $column_queries[]= '\"'.$estimation_column_name.'\": '.$estimation_column_value;


        $new_item = $this->monday('mutation {
            create_item (board_id: '.$board_id.', group_id: "'.$group_id.'", item_name: "'.addcslashes($item_name, '"').'", 
            column_values: "{
                '.implode(', ', $column_queries).'
                }") {
                    id
                }
            }');

        $item_id = $new_item['data']['create_item']['id'];

        // set comments for backlogs
        $this->setComments($this->timeline, $item_name);

        // create the first description/attachment update
        if ($user_story->description != null || count($user_story->attachments) > 0) {
            $this->dispatch_time = $this->dispatch_time->copy()->addSeconds($this->interval);
            $this->attachment($item_id, $user_story);
        }

        return $new_item;
    }

    public function migrateSprints($taiga_data, $board_id) {

        // let's get the board columns first
        $this->columns = $this->getBoardColumns($board_id);
        // Set the sprints we need to create as groups in Monday.com
        $this->setGroups($this->sprint_board_id);
        // let's clean the slate by remove all existing groups
        $this->deleteAllGroups();

        // create groups
        foreach ($taiga_data->milestones as $sprint) {
            $new_group = $this->monday('mutation {create_group (board_id: '.$board_id.', group_name: "'.$sprint->name.'") {id}}');
            $group_id = $new_group['data']['create_group']['id'];

            // First, we deal with US assigned to Sprints
            // rearrange user stories array
            $rearranged_user_stories = [];
            foreach ($taiga_data->user_stories as $user_story) {
                if ($user_story->milestone == $sprint->name)
                    $rearranged_user_stories[$user_story->sprint_order] = $user_story;
            }
            ksort($rearranged_user_stories);

            // create user stories
            foreach ($rearranged_user_stories as $user_story) {
                if ($user_story->milestone == $sprint->name) {
                    $new_user_story = $this->createUS($board_id, $group_id, $user_story);
                }
            }
        }
    }

    public function migrateBacklog($taiga_data, $board_id) {

        // let's get the board columns first
        $this->columns = $this->getBoardColumns($board_id);
        $this->setUsers();
        $this->setGroups($board_id);
        $group_id = $this->groups['New ideas'];

        // rearrange user stories array
        $backlog = [];
        foreach ($taiga_data->user_stories as $user_story) {
            if ($user_story->milestone == null)
                $backlog[] = $user_story;
        }

        // create user stories
        foreach ($backlog as $user_story) {
            $new_backlog = $this->createBacklog($board_id, $group_id, $user_story);
        }

    }

    /**
     * Create and queue jobs for each user story that first create the US description with or without attachments
     * and then create further updates for each item via API
     *
     * @param $item_id
     * @param $user_story
     */
    public function attachment($item_id, $user_story) {

        $subject = 'Description';
        $body = $user_story->description;
        $to = 'pulse-'.$item_id.'-c267e602ed99a01e612c__9734922@web-epoch-team.monday.com';

        SendAttachment::withChain([
            (new PostUpdate($item_id, $this->comments))->delay(now()->addMinute())
        ])->dispatch($to, $subject, $body, $user_story->attachments)->delay($this->interval);
    }

    /**
     * Set Sprints/Groups to be created for the target board
     *
     * @param $board_id
     */
    public function setGroups($board_id) {
        $monday_response = $this->monday('{boards (ids: '.$board_id.') {groups{id title}}}');
        $out = [];
        foreach ($monday_response['data']['boards'][0]['groups'] as $group) {
            $out[$group['title']] = $group['id'];
        }
        $this->groups = $out;
    }

    /**
     * Delete all groups in Monday's sprint board
     */
    public function deleteAllGroups() {
        foreach ($this->groups as $name => $id) {
            if ($name != 'Sample Group')
                $response = $this->monday('mutation {
                    delete_group (board_id: '.$this->sprint_board_id.', group_id: "'.$id.'") {
                    id
                    deleted
                    }
                    }
                    ');
        }
    }
}
