<?php

namespace IlBronza\DemoDBReplicator\Commands;

use IlBronza\DemoDBReplicator\DemoDBReplicator;
use Illuminate\Console\Command;

class ReplicateDB extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // protected $signature = 'mysqldump --defaults-file=/var/www/gestionale/dbopt.cnf idealpack_operations > maranza.sql';


    protected $signature = 'dbreplicator:replicate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Take a database dump';

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
        return DemoDBReplicator::replicate();
    }
}
