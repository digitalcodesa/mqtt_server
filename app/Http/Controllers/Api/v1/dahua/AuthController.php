<?php

namespace App\Http\Controllers\Api\v1\dahua;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Exception;
class AuthController extends Controller
{


    public function authorizeAccount($url, $userName, $password)
    {
        $Baseurl = "{$url}/brms/api/v1.0/accounts/authorize";

        $data = [
            'userName' => $userName,
        ];

        $headers = [
            'Host' => '192.168.1.5',

            'Connection'      => 'keep-alive',
            'Accept-Language' => 'en',
            'Time-Zone'       => 'Asia/Riyadh',
            'Content-Type'    => 'application/json;charset=UTF-8'
        ];

        $expectedKeys = ["realm", "randomKey", "encryptType", "publickey"];

        $response = Http::withHeaders($headers)
            ->withOptions(['verify' => false]) // تجاهل SSL self-signed
            ->post($Baseurl, $data);

        $responseData = $response->json();

        Log::info('Auth Response: ' . print_r($responseData, true));


        $containsAllKeys = collect($expectedKeys)->every(fn($key) => array_key_exists($key, $responseData));

        if ($containsAllKeys) {
            $signature = $this->generatSignature(
                $password,
                $userName,
                $responseData['realm'],
                $responseData['randomKey']
            );

            $this->generateRsaKeys();
            $publicKey = Storage::get('rsa_public_key.pem');
            $pureString = str_replace(
                ["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"],
                '',
                $publicKey
            );

            $step2Data = [
                'signature'   => $signature,
                'userName'    => $userName,
                'password'    => $password,
                'randomKey'   => $responseData['randomKey'],
                'publicKey'   => $pureString,
                'encryptType' => $responseData['encryptType'],
            ];

            $responseToken = $this->authorizeAccountStep2($url, $step2Data);
            Log::info('Step 2 Response: ' . print_r($responseToken, true));

            // $this->stopKeepAlive();

            // if (!empty($responseToken['token'])) {
            //     $this->startKeepAlive($responseToken['token'], $url);

            //     DahuaServer::updateOrCreate(
            //         ['url' => $url],
            //         [
            //             'token' => $responseToken['token'],
            //             'token_expires_at' => now()->addSeconds($responseToken['tokenRate']), // إذا أردت تخزين تاريخ الانتهاء
            //         ]
            //     );

            //     return $responseToken;
            // }

            return $responseToken;
        }
    }


    function generatSignature($password, $userName, $realm, $randomKey)
    {
        // Step-by-step MD5 hashing based on the specified logic
        $temp1 = md5($password);
        $temp2 = md5($userName . $temp1);
        $temp3 = md5($temp2);
        $temp4 = md5($userName . ":" . $realm . ":" . $temp3);
        $signature = md5($temp4 . ":" . $randomKey);

        return $signature;
    }


    function authorizeAccountStep2($URL, $data)
    {
        // API endpoint URL for the second authorization step
        $url = $URL . '/brms/api/v1.0/accounts/authorize';

        // Define headers for the request, including host, connection type, language, and content length
        $headers = [
            'Host' => '192.168.1.5',

            'Connection' => 'keep-alive',
            'Accept-Language' => 'en',
            'Time-Zone' => 'Asia/Riyadh',
            'Content-Type' => 'application/json;charset=UTF-8',
            'Content-Length' => '634'
        ];

        // Send POST request with the defined headers and data payload
        $response = Http::withHeaders($headers)
            ->withOptions(['verify' => false]) // Disables SSL verification for self-signed certs
            ->post($url, $data);
        $responseData = $response->json();

        if (isset($responseData['token'])) {
            // DahuaServer::updateOrCreate(
            //     ['url' => $URL],
            //     ['token' => $responseData['token']]
            // );
        }
        // Return the JSON response from the API
        return $responseData;
    }

    // Generate RSA Private Key and Public Key
    function generateRsaKeys()
    {
        // Generate RSA key pair
        $resource = openssl_pkey_new([
            "private_key_bits" => 2048,
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
        ]);

        // Extract private key in PKCS8 format
        openssl_pkey_export($resource, $privateKey);

        // Extract public key in X509 format
        $keyDetails = openssl_pkey_get_details($resource);
        $publicKey = $keyDetails['key'];

        // Optionally, you can store the keys in files
        Storage::disk('local')->put('rsa_private_key.pem', $privateKey);
        Storage::disk('local')->put('rsa_public_key.pem', $publicKey);

        return [
            'privateKey' => $privateKey,
            'publicKey' => $publicKey
        ];
    }




    // Use RSA Private Key for Decryption
    public static function decryptRSAByPrivateKey(string $text, string $privateKey): string
    {
        $decodedData = base64_decode($text);
        $decrypted = '';

        foreach (str_split($decodedData, 256) as $chunk) {
            if (!openssl_private_decrypt($chunk, $decryptedChunk, $privateKey)) {
                throw new \Exception("Decryption failed: " . openssl_error_string());
            }
            $decrypted .= $decryptedChunk;
        }

        return $decrypted;
    }

    // AES Decryption
    public static function decryptWithAES7(string $text, string $aesKey, string $aesVector): string
    {
        $decodedData = hex2bin($text);

        $decryptedData = openssl_decrypt(
            $decodedData,
            'AES-256-CBC',
            $aesKey,
            OPENSSL_RAW_DATA,
            $aesVector
        );

        if ($decryptedData === false) {
            throw new \Exception("AES decryption failed: " . openssl_error_string());
        }

        return $decryptedData;
    }

    public static function encryptPasswordWithAES(string $password, string $secretKeyWithRsa, string $secretVectorWithRsa): ?string
    {
        try {
            $privateKey = Storage::get('rsa_private_key.pem');


            // Decrypt the secret key and vector using RSA
            $secretKey = self::decryptRSAByPrivateKey($secretKeyWithRsa, $privateKey);
            $secretVector = self::decryptRSAByPrivateKey($secretVectorWithRsa, $privateKey);

            //  dd([$secretKey,$secretVector]);
            // Encrypt the password using AES
            return self::decryptWithAES7($password, $secretKey, $secretVector);
        } catch (\Exception $e) {
            Log::error('Error encrypting password: ' . $e->getMessage());
            return null;
        }
    }



        function getMqConfig($secretKey, $secretVector, $clientType, $clientMac,  $project, $method, $token, $url)
    {

        // Validate the incoming request
        $validatedData = [
            'secretKey' => $secretKey,
            'secretVector' => $secretVector,
            'clientType' => $clientType,
            'clientMac' => $clientMac,
            'project' => $project,
            'method' => $method,
            'token' => $token,
            'url' => $url,
        ];



        // API endpoint URL for the GetMqConfig request
        $baseUrl = "{$url}/brms/api/v1.0/BRM/Config/GetMqConfig";

        // Define headers for the request
        $headers = [


            'Connection' => 'keep-alive',
            'Accept-Language' => 'en',
            'Time-Zone' => 'asia/riyadh',
            'Content-Type' => 'application/json;charset=UTF-8',
        ];



        // Add the token from the request data
        if (isset($token)) {
            $headers['X-Subject-Token'] = $token;
        }

        // Send POST request with the defined headers and data payload
        $response = Http::withHeaders($headers)
            ->withOptions(options: ['verify' => false]) // Disables SSL verification for self-signed certs
            ->post($baseUrl, $validatedData);


        $responseData = $response->json(); // Decode the response JSON into an array


        $password = $responseData['data']['password'] ?? "";

        $decryptedText = $this->encryptPasswordWithAES($password, $secretKey, $secretVector);
        try {
            $decryptedText = $this->encryptPasswordWithAES($password, $secretKey, $secretVector);
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        $responseData['decryptedText'] = $decryptedText; // Add the new key-value pair
        Log::info('Step 3 Response: ' . print_r($responseData, true));
        return $responseData;
    }
}
