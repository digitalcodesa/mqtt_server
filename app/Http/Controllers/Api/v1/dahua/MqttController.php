<?php

namespace App\Http\Controllers\Api\v1\dahua;


use App\Http\Controllers\Controller;
use App\Services\MqttService;
use App\Jobs\StartMqttListenerJob;
use App\Models\Compound;
use App\Models\DahuaServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
class MqttController extends Controller
{
    protected $mqttService;

    public function __construct(MqttService $mqttService)
    {
        $this->mqttService = $mqttService;
    }


    public function getServersData()
    {

        $authController = new AuthController();

        $apiServer = config('app.API_SERVER_URL');
        // $apiServer = 'https://test.barriersolutions.co';
        Log::info($apiServer);

        try {
            $response = Http::withoutVerifying()->get($apiServer . '/api/v1/dahua-servers');

            if ($response->successful()) {
                $dahuaServers = $response->json()['data'];
            } else {
                Log::error('Failed to fetch Dahua servers: ' . $response->body());
                return [];
            }
        } catch (\Exception $e) {
            Log::error('Error while calling API: ' . $e->getMessage());
            return [];
        }

        $servers = [];

        foreach ($dahuaServers as $server) {
            try {
                // Decrypt password if it's encrypted
                $password = $server['password'];
                $password = Crypt::decryptString($password);

                // Get authorization data from AuthController
                $authResponse = $authController->authorizeAccount(
                    $server['url'],
                    $server['username'],
                    $password
                );

                // Validate auth response
                if (!$authResponse || !isset($authResponse['secretKey']) || !isset($authResponse['secretVector']) || !isset($authResponse['token'])) {
                    Log::error("Invalid auth response for server {$server['name']}: " . json_encode($authResponse));
                    continue;
                }

                // Parse the auth response
                $configResponse = $authController->getMqConfig(
                    $authResponse['secretKey'],
                    $authResponse['secretVector'],
                    "WINPC_V2",
                    "123456",
                    "Barrier Solutions",
                    "post",
                    $authResponse['token'],
                    $server['url']
                );

                // Validate config response
                if (!$configResponse || !isset($configResponse['data']['mqtt']) || !isset($configResponse['data']['userName']) || !isset($configResponse['decryptedText'])) {
                    Log::error("Invalid config response for server {$server['name']}: " . json_encode($configResponse));
                    continue;
                }

                $mqtt = $configResponse['data']['mqtt'];
                // Method 1: Using explode (recommended)
                $mqttParts = explode(':', $mqtt);
                $mqttHost = $mqttParts[0]; // "209.42.25.52"
                $mqttPort = isset($mqttParts[1]) ? (int)$mqttParts[1] : 1883; // 1883

                // try {
                //     $compoundResponse = Http::withoutVerifying()->get($apiServer . '/api/v1/compounds', [
                //         'dahua_server_id' => $server['id']
                //     ]);

                //     if ($compoundResponse->successful()) {
                //         $compoundData = collect($compoundResponse->json()['data'])->first();
                //     } else {
                //         Log::error("Failed to fetch compound for server {$server['id']}: " . $compoundResponse->body());
                //         $compoundData = null;
                //     }
                // } catch (\Exception $e) {
                //     Log::error("Error fetching compound for server {$server['id']}: " . $e->getMessage());
                //     $compoundData = null;
                // }

                $servers[] = [
                    'server' => $mqttHost,
                    'port' => $mqttPort,
                    'client_id' => 2,
                    'username' => $configResponse['data']['userName'],
                    'password' => $configResponse['decryptedText'],
                    'topic' => 'mq/common/msg/topic',
                    // 'compound_id' => $compoundData ? $compoundData['id'] : null,
                ];

                Log::info("Successfully retrieved auth data for Dahua server: {$server['name']}");
            } catch (\Exception $e) {
                Log::error("Failed to get auth data for Dahua server {$server['name']}: " . $e->getMessage());
            }
        }

        return $servers;
    }

    public function startMultipleListeners()
    {

        $servers = $this->getServersData();
        
        // Validate servers array structure
        if (!is_array($servers) || empty($servers)) {
            return [
                'status' => 'error',
                'message' => 'Servers parameter must be a non-empty array'
            ];
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($servers as $index => $serverConfig) {
            // Validate required fields for each server
            $requiredFields = ['server', 'port', 'client_id', 'username', 'password'];
            $missingFields = [];

            foreach ($requiredFields as $field) {
                if (!isset($serverConfig[$field]) || ($serverConfig[$field] === null || $serverConfig[$field] === '')) {
                    $missingFields[] = $field;
                }
            }

            // compound_id is optional - use a default if not available
            // if (!isset($serverConfig['compound_id']) || $serverConfig['compound_id'] === null) {
            //     $serverConfig['compound_id'] = 'default_compound_' . ($index + 1);
            //     Log::warning("No compound_id found for server {$serverConfig['server']}, using default: {$serverConfig['compound_id']}");
            // }

            if (!empty($missingFields)) {
                $results[] = [
                    'index' => $index,
                    'server' => $serverConfig['server'] ?? 'unknown',
                    // 'compound_id' => $serverConfig['compound_id'] ?? 'unknown',
                    'status' => 'error',
                    'message' => 'Missing required fields: ' . implode(', ', $missingFields)
                ];
                $failureCount++;
                continue;
            }

            try {
                $jobId = StartMqttListenerJob::dispatch(
                    $serverConfig['server'],
                    $serverConfig['port'],
                    $serverConfig['client_id'],
                    $serverConfig['username'],
                    $serverConfig['password'],
                    $serverConfig['topic'] ?? 'mq/common/msg/topic',
                    // $serverConfig['compound_id']
                );

                // Store active listener info
                $listenerKey = "mqtt_listener_{$serverConfig['client_id']}";
                Cache::put($listenerKey, [
                    'server' => $serverConfig['server'],
                    'port' => $serverConfig['port'],
                    'client_id' => $serverConfig['client_id'],
                    // 'compound_id' => $serverConfig['compound_id'],
                    'status' => 'active',
                    'started_at' => now()
                ], now()->addHours(24));

                $results[] = [
                    'index' => $index,
                    'server' => $serverConfig['server'],
                    // 'compound_id' => $serverConfig['compound_id'],
                    'status' => 'success',
                    'message' => 'MQTT listener dispatched successfully'
                ];
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'index' => $index,
                    'server' => $serverConfig['server'],
                    // 'compound_id' => $serverConfig['compound_id'],
                    'status' => 'error',
                    'message' => 'Failed to dispatch: ' . $e->getMessage()
                ];
                $failureCount++;
                Log::error("Failed to start MQTT listener for {$serverConfig['server']}: " . $e->getMessage());
            }
        }

        return [
            'status' => $failureCount === 0 ? 'success' : 'partial',
            'message' => "Started {$successCount} listeners, {$failureCount} failed",
            'summary' => [
                'total' => count($servers),
                'success' => $successCount,
                'failures' => $failureCount
            ],
            'results' => $results
        ];
    }

    /**
     * Get status of all active MQTT listeners
     */
    public function getListenersStatus()
    {
        $pattern = 'mqtt_listener_*';
        $keys = Cache::getRedis()->keys($pattern);

        $listeners = [];
        foreach ($keys as $key) {
            $listenerInfo = Cache::get($key);
            if ($listenerInfo) {
                $listeners[] = $listenerInfo;
            }
        }

        return response()->json([
            'status' => 'success',
            'active_listeners' => count($listeners),
            'listeners' => $listeners
        ]);
    }

    /**
     * Stop all MQTT listeners
     */
    public function stopAllListeners()
    {
        try {
            // Set global stop flag
            Cache::put('mqtt_global_stop', true, now()->addMinutes(30));

            // Clear listener cache
            $pattern = 'mqtt_listener_*';
            $keys = Cache::getRedis()->keys($pattern);

            foreach ($keys as $key) {
                Cache::forget($key);
            }

            Log::info('All MQTT listeners stopped');

            return response()->json([
                'status' => 'success',
                'message' => 'All MQTT listeners stopped successfully',
                'stopped_listeners' => count($keys)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to stop listeners: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start MQTT listener directly
     */
    public function startListener($server, $port, $clientId, $username, $password, $topic = 'mq/common/msg/topic', $compoundId)
    {



        try {
            $result = $this->mqttService->startListener($server, $port, $clientId, $username, $password, $topic, $compoundId);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start MQTT listener: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start MQTT listener as background job
     */
    public function startListenerJob($server, $port, $clientId, $username, $password, $topic = 'mq/common/msg/topic')
    {


        try {
            StartMqttListenerJob::dispatch(
                $server,
                $port,
                $clientId,
                $username,
                $password,
                $topic,
                // $compoundId
            );

            return response()->json([
                'status' => 'success',
                'message' => 'MQTT listener job dispatched successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dispatch MQTT listener job: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start with default Dahua settings
     */
}
