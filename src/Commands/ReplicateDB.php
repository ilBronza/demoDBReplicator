<?php

namespace IlBronza\DemoDBReplicator\Commands;

use IlBronza\DemoDBReplicator\DatabaseReplicator;
use Illuminate\Console\Command;

class ReplicateDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dbreplicator:replicate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Copy the configured source database to the staging database';

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
     * @return int
     */
    public function handle()
    {
        DatabaseReplicator::replicate();

        return 0;
    }
}
