# MQTT Server for Dahua Devices

## Overview

This project is a Laravel-based middleware designed to integrate with Dahua devices (Cameras, NVRs, etc.). It acts as a bridge that:
1.  **Authenticates** with Dahua devices using a custom secure handshake.
2.  **Retrieves MQTT Configurations** directly from the devices.
3.  **Listens for MQTT Events**, specifically vehicle capture events (`ipms.entrance.notifyVehicleCaptureInfo`).
4.  **Forwards Events** to a central API server for logging and processing.
5.  **Controls Devices**, such as sending remote "Open Door" (Open Sluice) commands.

It supports managing multiple device listeners simultaneously using background jobs and Redis for state management.

## Features

*   **Automated Dahua Auth**: Handles the complex multi-step authentication (Digest/RSA/AES) required by Dahua devices.
*   **Dynamic MQTT Setup**: Fetches MQTT credentials and connection details dynamically from the device.
*   **Event Forwarding**: Captures vehicle plate numbers and images, forwarding them to a central system log.
*   **Remote Control**: API endpoint to trigger gate/door opening on specific channels.
*   **Scalable Listeners**: Uses Laravel Queues to run multiple MQTT listeners in the background.
*   **Status Monitoring**: API to check the status of active listeners.

## Prerequisites

*   **PHP**: ^8.2
*   **Laravel**: ^12.0
*   **Composer**
*   **Database**: SQLite/MySQL (for Laravel Jobs table if using database queue).

## Installation

1.  **Clone the repository:**
    ```bash
    git clone <repository-url>
    cd mqtt_server
    ```

2.  **Install Dependencies:**
    ```bash
    composer install
    npm install
    ```

3.  **Environment Configuration:**
    Copy the example environment file:
    ```bash
    cp .env.example .env
    ```

    Update `.env` with your configuration. **Crucially**, you must add the `API_SERVER_URL` which points to your central API backend:
    ```env
    API_SERVER_URL=https://your-central-api-server.com
    


    # Configure Queue (Recommended to use Redis or Database)
    QUEUE_CONNECTION=database
    ```

4.  **Generate Application Key:**
    ```bash
    php artisan key:generate
    ```

5.  **Run Migrations:**
    (Required for the `jobs` table if using `QUEUE_CONNECTION=database`)
    ```bash
    php artisan migrate
    ```

## Running the Server

Since this application relies on long-running MQTT listeners processed as background jobs, you **must** run a queue worker.

1.  **Start the Queue Worker:**
    ```bash
    php artisan queue:work --timeout=0
    ```
    *Note: `--timeout=0` is important because MQTT listeners are long-running processes.*

2.  **Start the Listeners (Command Line):**
    You can start the listeners using the custom artisan command:
    ```bash
    php artisan alarms:listen
    ```

3.  **Start the Development Server (Optional for API access):**
    ```bash
    php artisan serve
    ```

## Usage / API Documentation

The application exposes several API endpoints to manage the MQTT listeners.

### Base URL: `/api/v1/mqtt`

#### 1. Start Multiple Listeners
Fetches all available Dahua servers from the central API, authenticates with them, and starts background MQTT listeners for each.

*   **Endpoint:** `POST /start-multiple-listeners`
*   **Response:**
    ```json
    {
        "status": "success",
        "message": "Started X listeners, Y failed",
        "results": [...]
    }
    ```

#### 2. Get Listeners Status
Returns the status of all currently active MQTT listeners (cached in Redis).

*   **Endpoint:** `GET /listeners-status`
*   **Response:**
    ```json
    {
        "status": "success",
        "active_listeners": 5,
        "listeners": [
            {
                "server": "192.168.1.100",
                "status": "active",
                "started_at": "..."
            }
        ]
    }
    ```

#### 3. Stop All Listeners
Sends a signal to stop all running MQTT listener jobs.

*   **Endpoint:** `POST /stop-all-listeners`

#### 4. Start Single Listener (Job)
Manually start a listener for a specific device as a background job.

*   **Endpoint:** `POST /start-listener-job`
*   **Body:**
    ```json
    {
        "server": "192.168.1.100",
        "port": 1883,
        "clientId": "client_1",
        "username": "admin",
        "password": "password",
        "topic": "mq/common/msg/topic"
    }
    ```

## Project Structure

The core logic is located in `app/Http/Controllers/Api/v1/dahua`:

*   **`AuthController.php`**: Handles the handshake with Dahua devices (Step 1 & 2 Auth, RSA key generation, AES decryption of passwords).
*   **`DeviceController.php`**: Contains the business logic for handling incoming MQTT messages (`handleEvent`) and sending commands (`openDoor`).
*   **`MqttController.php`**: Manages the lifecycle of listeners (start/stop/status) and interacts with the `MqttService`.

**Services & Jobs:**
*   **`App\Services\MqttService`**: Wraps the `php-mqtt/client` to establish connections and subscribe to topics.
*   **`App\Jobs\StartMqttListenerJob`**: The queueable job that keeps the MQTT connection alive in the background.
