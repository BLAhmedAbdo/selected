<?php

namespace App\Console\Commands;

use App\Services\ResponseEngine;
use Illuminate\Console\Command;
use Throwable;

class AiopsRespondCommand extends Command
{
    protected $signature = 'aiops:respond';

    protected $description = 'Continuously monitor active incidents and execute automated responses';

    public function handle(ResponseEngine $responseEngine): int
    {
        $this->info('AIOps Automated Incident Response Engine started.');
        // Run every 10 seconds for simulation purposes
        $intervalSeconds = 10;

        while (true) {
            $this->line('[' . now()->toDateTimeString() . '] Checking for open incidents...');

            try {
                $actionsTaken = $responseEngine->process();

                if (empty($actionsTaken)) {
                    $this->info('No actions required in this cycle.');
                } else {
                    foreach ($actionsTaken as $action) {
                        $statusText = $action['result'] === 'success' ? '<fg=green>SUCCESS</>' : '<fg=red>FAILED</>';
                        
                        $this->line(sprintf(
                            "  - Incident: <fg=cyan>%s</> | Action: <fg=yellow>%s</> | Result: %s",
                            $action['incident_id'],
                            $action['action_taken'],
                            $statusText
                        ));
                        
                        if (!empty($action['notes'])) {
                            $this->line("    Notes: " . $action['notes']);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->error('Response cycle failed: ' . $e->getMessage());
            }

            sleep($intervalSeconds);
        }

        return Command::SUCCESS;
    }
}
