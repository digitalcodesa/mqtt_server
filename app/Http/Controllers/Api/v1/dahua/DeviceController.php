<?php

namespace App\Http\Controllers\Api\v1\dahua;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;

class DeviceController extends Controller
{
    function handleEvent($topic)
    {
        $apiServer = config('app.API_SERVER_URL');

        // Extract data from the topic
        $eventId = $topic['id'];
        $channel_id = $topic['info']['channelId'];
        $plateNo = $topic['info']['plateNo'];
        $capturePicture = $topic['info']['capturePicture'];
        $originalData = $topic; // Keep original data for logging
        $topic_json = json_encode($topic);

        // Find the camera first to use its ID for logging




        try {
            $cameraResponse = Http::withoutVerifying()->get($apiServer . '/api/v1/dahua-cameras', [
                'channel_id' => $channel_id
            ]);

            if ($cameraResponse->successful()) {
                Log::info("Event logged to SystemLog for channel Success: " . $cameraResponse);
                $dahuaCamera = collect($cameraResponse->json()['data']);
                // Log::info("Event logged to SystemLog for channel Success 2: " . $dahuaCamera);
            } else {
                Log::error("Failed to fetch DahuaCamera: " . $cameraResponse->body());
                $dahuaCamera = null;
            }
        } catch (\Exception $e) {
            Log::error("Error fetching DahuaCamera: " . $e->getMessage());
            $dahuaCamera = null;
        }



        Log::info("Camera info: " . $dahuaCamera);

        if ($dahuaCamera->isNotEmpty()) {
            // Store the complete JSON message in SystemLog
            try {
                $logResponse = Http::withoutVerifying()->post($apiServer . '/api/v1/system-logs', [
                    'system_logable_id'   =>  $eventId,
                    'system_logable_type' => 'App\Models\DahuaCamera',
                    'module_name'         => 'MQTT_LISTENER',
                    'action'              => 'VEHICLE_CAPTURE_EVENT',
                    'new_value'           => $topic_json,
                    'ip_address'          => request()->ip() ?? 'MQTT_LISTENER',
                    'guard_name'          => 'api'
                ]);

                if ($logResponse->successful()) {
                    Log::info("Event logged to SystemLog for channel: " . $channel_id);
                } else {
                    Log::error("Failed to log event via API: " . $logResponse->body());
                }
            } catch (\Exception $e) {
                Log::error("Error sending log to API: " . $e->getMessage());
            }

            $dahua_server_id = $dahuaCamera['server_id'];



            try {
                $response = Http::withoutVerifying()->get($apiServer . '/api/v1/dahua-servers');

                if ($response->successful()) {
                    $dahuaServers = $response->json()['data'];
                    Log::info("Fetched Dahua servers successfully: " . json_encode($dahuaServers));
                } else {
                    Log::error('Failed to fetch Dahua servers: ' . $response->body());
                    return [];
                }
            } catch (\Exception $e) {
                Log::error('Error while calling API: ' . $e->getMessage());
                return [];
            }



            $servers = [];

            $password = null;
            $url = null;
            $username = null;
            foreach ($dahuaServers as $server) {
                if ($server['id'] == $dahua_server_id) {
                    Log::info("Server details:");
                    Log::info("URL: " . $server['url']);
                    Log::info("Username: " . $server['username']);
                    Log::info("Password: " . $server['password']);
                    $username = $server['username'];
                    $url = $server['url'];
                    $password = $server['password'];
                    Log::info("Found Dahua server: " . json_encode($server));
                    Log::info("Processing door open for camera: " . json_encode($server));
                }
            }



            $result = $this->openDoor($url, $username, $password, $channel_id);
        }
    }

    function openDoor($url, $username, $password, $channel_id)
    {

        // Instantiate the AuthController
        $authController = new AuthController();
        try {

            $decryptedPassword = Crypt::decryptString($password);
        } catch (\Exception $e) {

            return false;
        }
        Log::info("Password: " . $decryptedPassword);
        // Call the method from AuthController
        $authResponse = $authController->authorizeAccount($url, $username, $decryptedPassword);


        Log::info("Auth Response: " . json_encode($authResponse));
           if (!isset($authResponse['token'])) {
            Log::error("Token not found in auth response. Keys: " . implode(', ', array_keys($authResponse)));
            return false;
        }

        $token = $authResponse['token'];
        Log::info("Token extracted successfully: " . $token . "...");

        // Base API URL
        $baseUrl = "{$url}/ipms/api/v1.0/entrance/channel/remote-open-sluice/$channel_id";



        $body = [
            "correctCarNo" => "",
            "correctTime" => "0",
            "forceRecapture" => "1",
            "parkingSpaceStatisticsType" => "1",
            "saveRecord" => "0",
        ];

        try {
            // Make the GET request with headers and query parameters
            $response = Http::withHeaders([
                'Accept-Language' => 'en',
                'Content-Type' => 'application/json;charset=UTF-8',
                'X-Subject-Token' =>  $token,
            ])->withOptions(['verify' => false])->put($baseUrl, $body);

            if ($response->successful()) {
                Log::info('Door open success - Status: ' );

                // Log::info($response->json());

                return true;
            } else {
                // Handle errors
                Log::error('Door open failed - Status: ' . $response->status() . ', Message: ' . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            // Handle exceptions
            Log::error('Door open exception: ' . $e->getMessage());
            return false;
        }
    }
}
