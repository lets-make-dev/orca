<?php

namespace MakeDev\Orca\Console\Commands;

use Illuminate\Console\Command;
use MakeDev\Orca\WebTerm\WebTermApplication;
use React\EventLoop\Loop;

class WebTermServer extends Command
{
    protected $signature = 'orca:webterm';

    protected $description = 'Start the Orca WebTerm WebSocket server';

    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('WebTerm server can only run in local environment.');

            return self::FAILURE;
        }

        if (! config('orca.webterm.enabled', true)) {
            $this->error('WebTerm is disabled. Set ORCA_WEBTERM_ENABLED=true to enable.');

            return self::FAILURE;
        }

        $host = config('orca.webterm.host', '127.0.0.1');
        $port = (int) config('orca.webterm.port', 8085);

        $this->info("Starting Orca WebTerm server on {$host}:{$port}...");

        $loop = Loop::get();

        $app = new WebTermApplication($loop, $host, $port);

        $this->info("WebTerm server listening on {$app->getAddress()}. Press Ctrl+C to stop.");

        $loop->run();

        return self::SUCCESS;
    }
}
