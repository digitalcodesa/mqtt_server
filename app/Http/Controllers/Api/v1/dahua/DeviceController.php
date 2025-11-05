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
        $apiServer = env('API_SERVER_URL');

        // Extract data from the topic
        $eventId = $topic['id'];
        $channel_id = $topic['info']['channelId'];
        $plateNo = $topic['info']['plateNo'];
        $capturePicture = $topic['info']['capturePicture'];
        $originalData = $topic; // Keep original data for logging
        $topic_json = json_encode($topic);

        // Find the camera first to use its ID for logging
        try {
            $cameraResponse = Http::get($apiServer . '/api/v1/dahua-cameras', [
                'channel_id' => $channel_id
            ]);

            if ($cameraResponse->successful()) {
                $dahuaCamera = collect($cameraResponse->json()['data'])->first();
            } else {
                Log::error("Failed to fetch DahuaCamera: " . $cameraResponse->body());
                $dahuaCamera = null;
            }
        } catch (\Exception $e) {
            Log::error("Error fetching DahuaCamera: " . $e->getMessage());
            $dahuaCamera = null;
        }

        // Store the complete JSON message in SystemLog
        try {
            $logResponse = Http::post($apiServer . '/api/v1/system-logs', [
                'system_logable_id'   => $dahuaCamera ? $dahuaCamera['id'] : $eventId,
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

        Log::info("Camera info: " . $dahuaCamera);

        if (isset($dahuaCamera)) {
            Log::info("Processing door open for camera: " . $dahuaCamera);

            $client = $dahuaCamera['client'];
            $dahua_server = $dahuaCamera['server'];
            $password = Crypt::decrypt($dahua_server['password']);
            // Uncomment if you want to open the door
            // $result = $this->openDoor($dahua_server['url'], $dahua_server['username'], $password, $channel_id);
        }
    }

    function openDoor($url, $username, $password, $channel_id)
    {

        // Instantiate the AuthController
        $authController = new AuthController();

        // Call the method from AuthController
        $authResponse = $authController->authorizeAccount($url, $username, $password);



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
                'X-Subject-Token' => $authResponse['token'],
            ])->withOptions(['verify' => false])->put($baseUrl, $body);

            if ($response->successful()) {
                Log::info($response->json());

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
