<?php

namespace App\Console\Commands;

use App\Http\Controllers\MigrationController;
use Illuminate\Console\Command;

class Migrate2Monday extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:monday';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate Taiga 2 Monday';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $migrate = new MigrationController;
        $migrate->migrate();
    }
}
