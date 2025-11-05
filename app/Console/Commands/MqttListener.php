<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\v1\dahua\DeviceController;
use App\Http\Controllers\Api\v1\dahua\MqttController;
use App\Services\MqttService;
use Illuminate\Console\Command;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use \PhpMqtt\Client\MqttClient;
use \PhpMqtt\Client\ConnectionSettings;
use Illuminate\Support\Facades\Log;

class MqttListener extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alarms:listen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting MQTT listeners...');

        $mqttController = new MqttController(new MqttService());

        $result = $mqttController->startMultipleListeners();

        if (is_array($result)) {
            $this->info('Status: ' . $result['status']);
            $this->info('Message: ' . $result['message']);

            if (isset($result['summary'])) {
                $this->table(
                    ['Metric', 'Count'],
                    [
                        ['Total Servers', $result['summary']['total']],
                        ['Successful', $result['summary']['success']],
                        ['Failed', $result['summary']['failures']]
                    ]
                );
            }

            if (isset($result['results']) && !empty($result['results'])) {
                $this->info('Detailed Results:');
                foreach ($result['results'] as $serverResult) {
                    $status = $serverResult['status'] === 'success' ? '✓' : '✗';
                    $this->line("  {$status} Server: {$serverResult['server']} - {$serverResult['message']}");
                }
            }
        } else {
            $this->info('Command completed');
        }
    }
}
