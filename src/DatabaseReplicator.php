<?php

namespace IlBronza\DemoDBReplicator;

use DB;
use IlBronza\Ukn\Ukn;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Log;
use Symfony\Component\Process\Process;
use function config;
use function storage_path;

class DatabaseReplicator
{
    /**
     * Execute the console command.
     *
     * @return mixed
     */

	public function getDumpFilePath() : string
	{
		return config('dbreplicator.dumpFilePath') . config('dbreplicator.dumpFileName') . '.sql';
	}

    public function export()
    {
	    $fromConnection = config('dbreplicator.connections.source');
	    $mysqldumpPath = config('dbreplicator.mySqlDumpPath');

        $source = config("database.connections.{$fromConnection}");

	    $dumpDir = config('dbreplicator.dumpFilePath');
	    $dumpPath = $this->getDumpFilePath();

	    if (! File::exists($dumpDir)) {
		    File::makeDirectory($dumpDir, 0755, true);
	    }

	    $command = sprintf(
		    '%s --add-drop-table ' . config("dbreplicator.tcp_socketString") . ' %s -u%s --password=\'%s\' %s > %s',
		    $mysqldumpPath,
		    $source['unix_socket'] ?? '/var/run/mysqld/mysqld.sock',
		    $source['username'],
		    $source['password'],
		    $source['database'],
		    $dumpPath
	    );

        exec($command, $output, $status);

        if ($status !== 0)
            throw new \RuntimeException('Database export failed. Check for which mysqldump cli command to try if is right in the config');

		Log::info('Database export completed successfully.');

        return true;
    }

	public function import()
	{
		$toConnection = config('dbreplicator.connections.destination');
		$mysqlPath = config('dbreplicator.mysqlPath', 'mysql');

		$target = config("database.connections.{$toConnection}");

		$dumpPath = $this->getDumpFilePath();

		if (! File::exists($dumpPath))
			throw new \RuntimeException("Dump file not found at {$dumpPath}");

		$command = sprintf(
			'%s ' . config("dbreplicator.tcp_socketString") . ' -u%s --password=\'%s\' %s < %s', $mysqlPath, $target['username'], $target['password'], $target['database'], $dumpPath
		);

        // dd(['er' => $command, 'ok' => "mysql -S /var/run/mysqld/mysqld.sock -ularavel.dbuser --password='!d34lp4ck4pp4' idealpack_replications < /var/www/gestionale/storage/app/tmp/dbreplication.sql"]);

		exec($command, $output, $status);

		if ($status !== 0)
		{
			throw new \RuntimeException('Database import failed');
		}

		Log::info('Database import completed successfully.');

		if (File::exists($dumpPath)) {
			File::delete($dumpPath);
			Log::info("Deleted dump file at {$dumpPath}");
		}

		return true;
	}

	public function dropAllDestinationTables(): void
	{
		$connection = config('dbreplicator.connections.destination');

	    DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=0');

	    // Recupera i nomi delle tabelle
	    $database = config("database.connections.{$connection}.database");

	    $tables = DB::connection($connection)
	        ->table('information_schema.tables')
	        ->where('table_schema', $database)
	        ->pluck('TABLE_NAME');

	    // Droppa ogni tabella
	    foreach ($tables as $table) {
	        DB::connection($connection)->statement("DROP TABLE IF EXISTS `$table`");
	    }

	    // Riabilita i vincoli
	    DB::connection($connection)->statement('SET FOREIGN_KEY_CHECKS=1');

	    Log::info("✔️ All tables dropped from connection [$connection]");
	}
    // /**
    //  * Replicate the database
    //  *
    //  * @return mixed
    //  */

    static function replicate()
    {
        $replicator = new static;

        $replicator->export();

        $replicator->dropAllDestinationTables();

		$replicator->import();

		if(config('dbreplicator.migrate'))
		{
			$connection = config('dbreplicator.connections.destination');

			$output = shell_exec('cd ' . config('dbreplicator.stagingPath') . ' && php artisan migrate --database=' . $connection . ' --force 2>&1');

			$pieces = explode("\n", $output);

			foreach($pieces as $piece)
				if($result = trim($piece))
					Ukn::s($result);			
		}
    }
}