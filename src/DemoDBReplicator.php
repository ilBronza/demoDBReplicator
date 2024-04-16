<?php

namespace IlBronza\DemoDBReplicator;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** this class replicates a database from a source to a destination
 *  the source and destination databases are defined in the config file
 *  the dump file is created in the storage path
 *  the dump file is loaded in the destination database
 *  the dump file is deleted after the load
 *  the class returns a message with the result of the operation
 */

class DemoDBReplicator
{
    /**
     * return the config file of the source database
     *
     * @return string
     **/

    private function getSourceDatabaseConfigFile() : string
    {
        return config('dbreplicator.databases.source.configFile');
    }


    /**
     * return the config file of the destination database
     *
     * @return string
     **/

    private function getDestinationDatabaseConfigFile() : string
    {
        return config('dbreplicator.databases.destination.configFile');
    }


    /**
     * return the name of the source database
     *
     * @return string
     **/

    private function getSourceDatabaseName() : string
    {
        return config('dbreplicator.databases.source.name');
    }


    /**
     * return the name of the destination database
     *
     * @return string
     **/

    private function getDestinationDatabaseName() : string
    {
        return config('dbreplicator.databases.destination.name');
    }


    /**
     * return the type of the destination file
     *
     * @return string
     **/

    private function getDestinationFileType() : string
    {
        return 'sql';
    }


    /**
     * return the path of the destination file
     *
     * @return string
     **/

    private function getDestinationDumpFilePath() : string
    {
        return config('dbreplicator.dumpFilePath');
    }


    /**
     * return the full path of the destination file
     *
     * @return string
     **/

    private function getDestinationDumpFileFullPath() : string
    {
        return $this->getDestinationDumpFilePath() . config('dbreplicator.dumpFileName') . '.' . $this->getDestinationFileType();
    }


    /** 
     * prepare the dump command
     *
     * @return void
     */

    private function prepareDumpCommand()
    {
        $cmd = [
            'mysqldump',
            '--defaults-file=' . $this->getSourceDatabaseConfigFile(),
            $this->getSourceDatabaseName(),
            '>',
            $this->getDestinationDumpFileFullPath()
        ];

        $this->cmd = implode(' ', $cmd);
    }


    /**
     * prepare the load command
     *
     * @return void
     */

    private function prepareLoadCommand()
    {
        $loadCmd = [
            'mysql',
            '--defaults-file=' . $this->getDestinationDatabaseConfigFile(),
            $this->getDestinationDatabaseName(),
            '<',
            $this->getDestinationDumpFileFullPath()
        ];

        $this->loadCmd = implode(' ', $loadCmd);
    }


    /**
     * remove previous file if exists
     *
     * @return void
     */

    private function removePreviousFileIfExists()
    {
        if(File::exists($this->getDestinationDumpFileFullPath()))
            File::delete($this->getDestinationDumpFileFullPath());
    }


    /**
     * create folder if not exists
     *
     * @return void
     */

    private function prepareFolder()
    {
        $folder = $this->getDestinationDumpFilePath();

        if(! File::exists($folder))
            File::makeDirectory($folder, 0777, true);

        $this->removePreviousFileIfExists();
    }


    /**
     * process the command
     *
     * @param string $cmd
     * @return string
     * @throws \Exception
     */

    private function processCommand(string $cmd) : string
    {
        $process = Process::fromShellCommandline($cmd);

        $processOutput = '';

        $captureOutput = function ($type, $line) use (&$processOutput) {
            $processOutput .= $line;
        };

        $process->setTimeout(null)
            ->run($captureOutput);

        if ($process->getExitCode()) {
            $exception = new \Exception($cmd . " - " . $processOutput);
            report($exception);

            throw $exception;
        }

        return $processOutput;
    }


    /**
     * process dump command
     *
     * @return string
     */

    private function processDumpCommand() : string
    {
        return $this->processCommand(
            $this->cmd
        );
    }

    /**
     * Check if file exists
     *
     * @throws \Exception
     */

    private function checkFileExistence()
    {
        if(! File::exists($this->getDestinationDumpFileFullPath()))
            throw new \Exception('File not created');
    }

    /**
     * Execute the console loading command.
     *
     * @return mixed
     */

    private function processLoadingCommand()
    {
        return $this->processCommand(
            $this->loadCmd
        );
    }


    /**
     * Remove dump file if exists
     *
     * @throws \Exception
     */

    private function removeDumpFileIfExists()
    {
        if(File::exists($this->getDestinationDumpFileFullPath()))
            File::delete($this->getDestinationDumpFileFullPath());
    }


    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function execute()
    {
        //prepare dump command
        $this->prepareDumpCommand();

        // create destination folder
        $this->prepareFolder();

        // if dump command fails, return error
        if(! is_string($this->processDumpCommand()))
            return [
                'success' => false,
                'message' => "Dump of {$this->getSourceDatabaseName()} failed"
            ];

        // check file existence
        $this->checkFileExistence();

        // prepare load command
        $this->prepareLoadCommand();

        // load dump file
        $result = $this->processLoadingCommand();

        // remove dump file
        $this->removeDumpFileIfExists();

        //if load command works, return success
        if(is_string($result))
            return [
                'success' => true,
                'message' => "Database {$this->getSourceDatabaseName()} replicated to {$this->getDestinationDatabaseName()}"
            ];

        //otherwise return error
        return [
            'success' => false,
            'message' => "Replication of {$this->getSourceDatabaseName()} to {$this->getDestinationDatabaseName()} failed"
        ];
    }


    /**
     * Replicate the database
     *
     * @return mixed
     */

    static function replicate()
    {
        $replicator = new static;

        return $replicator->execute();
    }
}