<?php
// helpers/PhilSmsHelper.php
// SMS helper via PhilSMS API v3
// FIXED: Uses correct API endpoint (dashboard.philsms.com)
// FIXED: Reads ALL credentials from .env — no hardcoded API token

class PhilSmsHelper {

    /**
     * Get API token from .env only
     * No hardcoded fallback — returns empty string if missing
     */
    private static function getApiToken() {
        if (class_exists('EnvLoader')) {
            $token = EnvLoader::get('PHILSMS_API_TOKEN', '');
            if (!empty($token)) return $token;
        }
        
        // No hardcoded fallback — returns empty so error will trigger
        return '';
    }

    /**
     * Get Sender ID from .env only
     */
    private static function getSenderId() {
        if (class_exists('EnvLoader')) {
            $sender = EnvLoader::get('PHILSMS_SENDER_ID', '');
            if (!empty($sender)) return $sender;
        }
        
        // Safe default (not sensitive — this is a public sender name)
        return 'PhilSMS';
    }

    /**
     * Get API URL from .env only
     */
    private static function getApiUrl() {
        if (class_exists('EnvLoader')) {
            $url = EnvLoader::get('PHILSMS_API_URL', '');
            if (!empty($url)) return $url;
        }
        
        // Safe default endpoint (not sensitive)
        return 'https://dashboard.philsms.com/api/v3/sms/send';
    }

    /**
     * Send SMS via PhilSMS API v3
     *
     * @param string $recipient  Phone number (e.g., 09171234567 or 639171234567)
     * @param string $message    SMS content
     * @return array             ['success' => bool, 'data' => ..., 'error' => ...]
     */
    public static function sendSMS($recipient, $message) {
        // Clean phone number
        $formattedPhone = preg_replace('/[^0-9]/', '', $recipient);

        // Convert 09xxxxxxxxx → 639xxxxxxxxx
        if (substr($formattedPhone, 0, 1) === '0') {
            $formattedPhone = '63' . substr($formattedPhone, 1);
        }

        // Validate length (should be 12 digits: 639XXXXXXXXX)
        if (strlen($formattedPhone) < 11 || strlen($formattedPhone) > 13) {
            return [
                'success' => false,
                'error'   => "Invalid phone number format: {$recipient}"
            ];
        }

        $api_token = self::getApiToken();
        $sender_id = self::getSenderId();
        $url       = self::getApiUrl();

        if (empty($api_token)) {
            error_log("[PhilSMS] API token is missing in .env");
            return [
                'success' => false,
                'error'   => 'PhilSMS API token is not configured in .env'
            ];
        }

        $payload = [
            'recipient' => $formattedPhone,
            'sender_id' => $sender_id,
            'type'      => 'plain',
            'message'   => $message
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $api_token,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log("[PhilSMS] cURL Error: " . $error);
            return [
                'success' => false,
                'error'   => 'cURL Error: ' . $error
            ];
        }

        $decoded = json_decode($response, true);

        // Success if HTTP 200/201 AND response has data.uid or status=success
        $isSuccess = (
            ($httpCode === 200 || $httpCode === 201) &&
            is_array($decoded) &&
            (
                (isset($decoded['status']) && strtolower($decoded['status']) === 'success') ||
                isset($decoded['data']['uid'])
            )
        );

        if ($isSuccess) {
            error_log("[PhilSMS] SMS sent to {$formattedPhone}, UID: " . ($decoded['data']['uid'] ?? 'N/A'));
            return [
                'success' => true,
                'data'    => $decoded
            ];
        }

        error_log("[PhilSMS] SMS failed to {$formattedPhone}. HTTP {$httpCode}: " . substr($response, 0, 300));

        return [
            'success'   => false,
            'http_code' => $httpCode,
            'response'  => $decoded,
            'error'     => $decoded['message'] ?? 'Unknown PhilSMS error'
        ];
    }
}

/**
 * Global helper for backward compatibility
 */
function sendPhilSMS($recipient, $message) {
    return PhilSmsHelper::sendSMS($recipient, $message);
}