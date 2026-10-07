<?php

namespace IlBronza\DemoDBReplicator\Tests\Fixtures;

use IlBronza\DemoDBReplicator\MySqlClient;
use Mockery;
use RuntimeException;
use Symfony\Component\Process\Process;

class RecordingMySqlClient extends MySqlClient
{
    public array $commands = [];
    public array $credentials = [];
    public array $inputs = [];
    public array $chunks = [[Process::OUT, "CREATE TABLE example (id int);\n"]];
    public bool $successful = true;
    public ?RuntimeException $runException = null;
    public bool $stopped = false;

    protected function process(array $command, $input = null, ?string $directory = null): Process
    {
        $this->commands[] = $command;
        $this->inputs[] = is_resource($input) ? stream_get_contents($input) : $input;

        foreach ($command as $argument) {
            if (strpos($argument, '--defaults-file=') === 0) {
                $path = substr($argument, strlen('--defaults-file='));
                $this->credentials[] = [
                    'path' => $path, 'contents' => file_get_contents($path),
                    'mode' => fileperms($path) & 0777,
                ];
            }
        }

        $process = Mockery::mock(Process::class);
        $process->shouldReceive('run')->once()->andReturnUsing(function ($callback) {
            if ($this->runException) {
                throw $this->runException;
            }

            foreach ($this->chunks as [$type, $buffer]) {
                $callback($type, $buffer);
            }

            return $this->successful ? 0 : 1;
        });
        $process->shouldReceive('clearOutput')->andReturnSelf();
        $process->shouldReceive('clearErrorOutput')->andReturnSelf();
        $process->shouldReceive('stop')->with(0)->andReturnUsing(function () {
            $this->stopped = true;

            return 1;
        });
        $process->shouldReceive('isSuccessful')->andReturnUsing(function () {
            return $this->successful;
        });

        return $process;
    }
}
