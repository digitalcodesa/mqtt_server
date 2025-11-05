<?php

namespace App\Services;

use App\Http\Controllers\Api\v1\dahua\DeviceController;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttService
{
    public function startListener($server, $port, $clientId, $username, $password, $topic = 'mq/common/msg/topic')
    {
        $clean_session = true;
        $mqtt_version = MqttClient::MQTT_3_1;

        $connectionSettings = (new ConnectionSettings)
            ->setConnectTimeout(10)
            ->setUsername($username)
            ->setPassword($password)
            ->setUseTls(true)
            ->setTlsSelfSignedAllowed(true)
            ->setTlsVerifyPeer(false)
            ->setTlsVerifyPeerName(false)
            ->setKeepAliveInterval(60);

        $mqtt = new MqttClient($server, $port, $clientId, $mqtt_version);

        try {
            $mqtt->connect($connectionSettings, $clean_session);
            Log::info("MQTT Client connected successfully to {$server}:{$port}");

            $mqtt->subscribe($topic, function ($topic, $message) {
                Log::info(sprintf("Received message on topic [%s]: %s", $topic, $message));

                // Decode the JSON message
                $decodedMessage = json_decode($message, true);

                // Check if the required key exists in the decoded message
                if (isset($decodedMessage['method']) && $decodedMessage['method'] == "ipms.entrance.notifyVehicleCaptureInfo") {

                    if (isset($decodedMessage['info']['channelId'])) {
                        $channelId = $decodedMessage['info']['channelId'];
                        Log::info("Extracted channelId: $channelId");

                        // Instantiate the DeviceController
                        $deviceController = app(DeviceController::class);

                        // Call the handleEvent method with the extracted message
                        $deviceResponse = $deviceController->handleEvent($decodedMessage);
                        Log::info(sprintf("Auth Response: %s", $deviceResponse));
                    } else {
                        Log::error('channelId not found in the message.');
                    }
                }
            }, 0);

            $mqtt->loop(true);

            return [
                'status' => 'success',
                'message' => 'MQTT listener started successfully'
            ];
        } catch (ConnectingToBrokerFailedException $e) {
            Log::error('Failed to connect to the MQTT broker.');
            Log::error('Error Code: ' . $e->getCode());
            Log::error('Error Message: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Failed to connect to MQTT broker: ' . $e->getMessage()
            ];
        } catch (\Exception $e) {
            Log::error('An unexpected error occurred.');
            Log::error('Error Code: ' . $e->getCode());
            Log::error('Error Message: ' . $e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Unexpected error: ' . $e->getMessage()
            ];
        }
    }
}
