<?php
// PusherHelper.php — optimized with short timeouts + logging
// FIXED: Added connect timeout, reduced timeout, added error logging
// SECURITY NOTE: Sa production, ilipat ang credentials sa .env at i-enable ang SSL verify

class PusherHelper {
    private static $app_id  = '2190387';
    private static $key     = '9bfff3facb287d7bbcbd';
    private static $secret  = '3466c2f14566a8b59d30';
    private static $cluster = 'ap1';

    /**
     * Trigger a real-time event on a Pusher channel via REST API
     *
     * @param string $channel Name of the channel (e.g., 'compliance-channel')
     * @param string $event   Name of the event (e.g., 'global-update' or 'task_updated')
     * @param mixed  $data    Array or string data payload to broadcast
     * @return bool|string    True on success, or error message string on failure
     */
    public static function trigger($channel, $event, $data) {
        $host = "api-" . self::$cluster . ".pusher.com";
        $path = "/apps/" . self::$app_id . "/events";

        // 1. Properly structure payload: 'channels' MUST be an array, 'data' MUST be a JSON string
        $payloadData = is_string($data) ? $data : json_encode($data);

        $bodyParams = [
            'name'     => $event,
            'channels' => [$channel],
            'data'     => $payloadData
        ];

        $postBody = json_encode($bodyParams);

        // 2. Compute parameters for Authentication
        $time           = time();
        $bodyMd5        = md5($postBody);
        $authVersion    = "1.0";

        $queryString = "auth_key=" . self::$key .
                       "&auth_timestamp=" . $time .
                       "&auth_version=" . $authVersion .
                       "&body_md5=" . $bodyMd5;

        // 3. Build HMAC-SHA256 Signature String
        $stringToSign  = "POST\n" . $path . "\n" . $queryString;
        $authSignature = hash_hmac('sha256', $stringToSign, self::$secret);

        $fullUrl = "https://" . $host . $path . "?" . $queryString . "&auth_signature=" . $authSignature;

        // 4. Send cURL Request — SHORT TIMEOUTS para hindi mag-hang ang background
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json'
            ],
            CURLOPT_CONNECTTIMEOUT => 3,   // ✅ 3s connection timeout
            CURLOPT_TIMEOUT        => 5,   // ✅ 5s total timeout
            CURLOPT_SSL_VERIFYPEER => false, // ⚠️ Local/Laragon only — set TRUE in production
            CURLOPT_SSL_VERIFYHOST => 0      // ⚠️ Local/Laragon only — set 2 in production
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[PUSHER] cURL Error: " . $curlError);
            return "cURL Error: " . $curlError;
        }

        if ($httpCode === 200) {
            return true;
        }

        error_log("[PUSHER] HTTP {$httpCode}: {$response}");
        return "HTTP Error {$httpCode}: {$response}";
    }
}

/**
 * Procedural wrapper function for simple usage throughout the system
 */
if (!function_exists('triggerPusherUpdate')) {
    function triggerPusherUpdate($event, $data, $channel = 'compliance-channel') {
        return PusherHelper::trigger($channel, $event, $data);
    }
}