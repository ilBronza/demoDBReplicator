<?php

namespace IlBronza\DemoDBReplicator\Commands;

use IlBronza\DemoDBReplicator\ProductionDatabasePuller;
use Illuminate\Console\Command;
use Throwable;

class PullProductionDB extends Command
{
    protected $signature = 'dbreplicator:pull {--no-migrate : Skip local migrations after import}';

    protected $description = 'Download the production database over SSH and replace the local database';

    public function handle(ProductionDatabasePuller $puller): int
    {
        try {
            $puller->pull(! $this->option('no-migrate'), function (string $message) {
                $this->line($message);
            });
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        $this->info('Production database copied to local successfully.');

        return 0;
    }
}
