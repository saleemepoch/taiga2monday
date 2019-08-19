## Taiga2Monday Migration

This is a very quick and dirty script to import our projects from locally installed Taiga to Monday.com.

It is designed to cater for our particular setup, i.e. Taiga Projects with multiple sprints and user stories
 (with comments and attachments). The objective is to import all sprints into Sprints Board in Monday.com and
  all the backlog (user stories outside of sprints) into its own Features Board.
  
We are putting it out there for anyone in a similar situation to ours who wants to quickly migrate most of the 
data over from Taiga to Monday.com. Of course, you will need to amend the code/structure to fit your needs.
At the very least will save you a lot of time it took us to experiment with Taiga and Monday.com API until we
finally got it working to a reasonably acceptable level.

### How it works
Aside from the usual _git clone_ and _composer install_ you will need to set up Laravel to handle jobs and 
queues because it uses SMTP to send attachments via email, since Monday.com API does not cater for 
attachments, yet.

#### Queues and Jobs
So, do:

`php artisan queue:table`

then:
`php artisan migrate`  

Next, change the QUEUE_CONNECTION to database in your .env file:

`QUEUE_CONNECTION=database`

This will ensure emails are sent to Monday.com with some delay, as Monday.com's rate-limits will kick in and 
block further emails from coming in.

To keep the chronological order of comments on each User Story, comments (or updates) for each Monday.com 
item will be posted via API after some delay, to make sure email with the first comment (and possibly attachments)
has been processed first.

**IMPORTANT NOTE**

When dealing with queues, whenever you make any changes in any of the Jobs or methods, remember to clear 
config cache: 

`php artisan config:clear`

#### .env

1. Remember to set your db username and password
2. Set your SMTP details to send emails (or configure API settings if you're using Mailgun or Sendgrid)
3. Set your Monday.com API key `MONDAY_API`. [Here is how to get your key](https://monday.com/developers/v2#authentication-section-accessing-tokens) 

#### MigrationController
The only three settings you need to worry about here are:

1. `$sprint_board_id`. That's your Monday.com board ID for where you want all your sprints to go.
2. `$backlog_board_id`. That's your Monday.com board ID for where you want all your backlog user stories to 
go.
3. `$taiga_file`. This is the export of your project saved as a huge json file. You should place this in 
your `storage/app` folder.

Before running the migration, you need to run the queue worker, like so:

`php artisan queue:work --timeout=0`

Once set, you can run the script through the web for smaller projects from here 
`http://yourdomain.com/migrate` (White blank screen means everything went well. Sorry, didn't have the time to
write a fancy message.) If there is an error, you will see it.

For bigger projects, /migrate request will very likely time out. So you should run the migration from CLI
instead, like so:

`php artisan migrate:monday`

Again, if everything goes well, the command will return without any message. Otherwise, you will know :)

#### Limitations
Currently, the script does not import tasks created under each User Story. We didn't need to, but if you do
go ahead and contribute.

#### Useful Monday.com APIv2 queries

Here is a collection of working queries you might find useful:

##### Create a Group

        $monday_response = $this->monday('mutation {
            create_group (board_id: 293728442, group_name: "Sprint 111") {
                id
                }
             }');

##### Create a Board
        $monday_response = $this->monday('mutation{create_board (board_name: "my board 123", board_kind: public) {id}}');

##### List Groups

        $monday_response = $monday_response = $this->monday('{boards (ids: 293728442) {groups{id title color items {id name} }}}');

##### List boards
        $monday_response = $this->monday('{boards(limit:10){id name}}');

##### List Columns
        $monday_response = $this->monday('{boards (ids: 293728442) {owner{id} columns {id title type}}}');      

##### Get Users
        $monday_response = $this->monday('{users (kind: non_guests) {id email
            teams {id name}
            account {name id}
        }}');

##### Create an Update with a checklist
        $body = '
        <ul class="checklist">
            <li class="checklist_task is_checked">One</li>
            <li class="checklist_task">2</li>
            <li class="checklist_task">III</li>
        </ul>
        ';
        $response = $this->monday('mutation {
            create_update (item_id: 298239245, body: "'.addslashes($body).'") {
            id
            }
        }');
        
        
