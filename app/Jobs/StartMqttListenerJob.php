<?php

namespace App\Jobs;

use App\Services\MqttService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StartMqttListenerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $server;
    protected $port;
    protected $clientId;
    protected $username;
    protected $password;
    protected $topic;
    protected $compoundId;

    public function __construct($server, $port, $clientId, $username, $password, $topic = 'mq/common/msg/topic')
    {
        $this->server = $server;
        $this->port = $port;
        $this->clientId = $clientId;
        $this->username = $username;
        $this->password = $password;
        $this->topic = $topic;

    }

    public function handle()
    {
         Log::info('MQTT Listener Job Result: ' );
        $mqttService = new MqttService();
        $result = $mqttService->startListener(
            $this->server,
            $this->port,
            $this->clientId,
            $this->username,
            $this->password,
            $this->topic

        );

        Log::info('MQTT Listener Job Result: ' . json_encode($result));
    }
}
