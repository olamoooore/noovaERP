<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class SettingsController extends Controller
{
    /**
     * Test SMTP connectivity.
     * Tests the connection to the SMTP server using provided or stored credentials.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testSmtp(Request $request)
    {
        Log::info('Test SMTP Endpoint HIT', $request->all());

        $validated = $request->validate([
            'host' => 'required',
            'port' => 'required',
            'encryption' => 'nullable',
            'username' => 'nullable',
            'password' => 'nullable',
            'from_address' => 'nullable',
            'timeout' => 'nullable',
        ]);

        $timeout = (int) ($validated['timeout'] ?? 10);
        $encryption = $validated['encryption'] ?? 'tls';
        
        // If password is masked or empty, use the stored password
        $password = $validated['password'] ?? '';
        if (empty($password) || $password === '********') {
            $existingConfig = Setting::getValue('mail_config', []);
            $password = $existingConfig['password'] ?? '';
        }

        $host = $validated['host'];
        $port = (int) $validated['port'];
        $username = $validated['username'];
        $fromAddress = $validated['from_address'] ?? $username;

        $result = [
            'success' => false,
            'message' => '',
            'details' => [],
        ];

        try {
            // Step 1: Test basic socket connectivity
            Log::info("Testing socket connection to {$host}:{$port}");
            $socketTest = $this->testSocketConnection($host, $port, $timeout);
            
            if (!$socketTest['success']) {
                $result['message'] = 'Connection failed: ' . $socketTest['message'];
                $result['details'] = $socketTest['details'];
                Log::error('SMTP Socket Connection Failed', $result);
                return response()->json($result, 422);
            }

            $result['details']['socket'] = 'Connected to ' . $host . ':' . $port;

            // Step 2: Test SMTP authentication using PHP's built-in functions
            Log::info("Testing SMTP authentication for {$username}");
            $smtpTest = $this->testSmtpAuthentication($host, $port, $username, $password, $encryption, $timeout);

            if ($smtpTest['success']) {
                $result['success'] = true;
                $result['message'] = 'SMTP connection successful! The mail server is reachable and credentials are valid.';
                $result['details']['auth'] = 'Authentication successful';
                $result['details']['server_info'] = $smtpTest['server_info'] ?? [];
            } else {
                $result['message'] = 'SMTP authentication failed: ' . $smtpTest['message'];
                $result['details']['auth'] = 'Authentication failed';
                Log::error('SMTP Authentication Failed', $result);
            }

        } catch (Exception $e) {
            $result['message'] = 'SMTP test failed: ' . $e->getMessage();
            $result['details']['error'] = $e->getMessage();
            Log::error('SMTP Test Error', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Test basic socket connection to the SMTP server.
     */
    private function testSocketConnection(string $host, int $port, int $timeout): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'details' => [],
        ];

        $startTime = microtime(true);
        
        // Try to open a socket connection
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $connectTime = round((microtime(true) - $startTime) * 1000, 2);

        if (!$socket) {
            $result['message'] = "Could not connect to {$host}:{$port}. Error: {$errstr} (Code: {$errno})";
            $result['details'] = [
                'error_code' => $errno,
                'error_message' => $errstr,
                'connection_time_ms' => $connectTime,
            ];
            return $result;
        }

        // Read the server greeting
        $greeting = fgets($socket);
        fclose($socket);

        $result['success'] = true;
        $result['message'] = 'Socket connection successful';
        $result['details'] = [
            'connection_time_ms' => $connectTime,
            'server_greeting' => trim($greeting ?? ''),
        ];

        return $result;
    }

    /**
     * Test SMTP authentication by sending SMTP commands.
     */
    private function testSmtpAuthentication(string $host, int $port, string $username, string $password, string $encryption, int $timeout): array
    {
        $result = [
            'success' => false,
            'message' => '',
            'server_info' => [],
        ];

        // Build connection string based on encryption
        $remote = 'tcp://' . $host . ':' . $port;
        if ($encryption === 'ssl') {
            $remote = 'ssl://' . $host . ':' . $port;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            $result['message'] = "Could not connect: {$errstr} ({$errno})";
            return $result;
        }

        stream_set_timeout($socket, $timeout);

        try {
            // Read greeting
            $greeting = $this->readSmtpResponse($socket);
            $result['server_info']['greeting'] = trim($greeting);

            if (!$this->isSuccessCode($greeting, 220)) {
                $result['message'] = 'Invalid server greeting: ' . $greeting;
                return $result;
            }

            // Send EHLO
            fwrite($socket, "EHLO localhost\r\n");
            $ehloResponse = $this->readSmtpResponse($socket);
            $result['server_info']['ehlo'] = trim($ehloResponse);

            if (!$this->isSuccessCode($ehloResponse, 250)) {
                $result['message'] = 'EHLO failed: ' . $ehloResponse;
                return $result;
            }

            // Handle TLS if needed
            if ($encryption === 'tls' && strpos($ehloResponse, 'STARTTLS') !== false) {
                fwrite($socket, "STARTTLS\r\n");
                $tlsResponse = $this->readSmtpResponse($socket);
                $result['server_info']['starttls'] = trim($tlsResponse);

                if ($this->isSuccessCode($tlsResponse, 220)) {
                    // Enable crypto for TLS with relaxed SSL verification
                    stream_context_set_option($socket, [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ],
                    ]);
                    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                        $result['message'] = 'Failed to enable TLS encryption';
                        return $result;
                    }

                    // Send EHLO again after TLS
                    fwrite($socket, "EHLO localhost\r\n");
                    $ehloResponse2 = $this->readSmtpResponse($socket);
                    $result['server_info']['ehlo_after_tls'] = trim($ehloResponse2);
                }
            }

            // Check if AUTH is supported
            $authMethods = [];
            if (preg_match('/AUTH\s+(.+)/i', $ehloResponse, $matches)) {
                $authMethods = array_map('trim', explode(' ', strtoupper($matches[1])));
            }
            $result['server_info']['auth_methods'] = $authMethods;

            // Try LOGIN authentication
            if (empty($authMethods) || in_array('LOGIN', $authMethods)) {
                fwrite($socket, "AUTH LOGIN\r\n");
                $authResponse = $this->readSmtpResponse($socket);

                if ($this->isSuccessCode($authResponse, 334)) {
                    // Send username
                    fwrite($socket, base64_encode($username) . "\r\n");
                    $userResponse = $this->readSmtpResponse($socket);

                    if ($this->isSuccessCode($userResponse, 334)) {
                        // Send password
                        fwrite($socket, base64_encode($password) . "\r\n");
                        $passResponse = $this->readSmtpResponse($socket);

                        if ($this->isSuccessCode($passResponse, 235)) {
                            $result['success'] = true;
                            $result['message'] = 'Authentication successful';
                        } else {
                            $result['message'] = 'Authentication failed (invalid credentials): ' . $passResponse;
                        }
                    } else {
                        $result['message'] = 'Username rejected: ' . $userResponse;
                    }
                } else {
                    $result['message'] = 'AUTH LOGIN not accepted: ' . $authResponse;
                }
            } else {
                $result['message'] = 'No supported authentication methods available. Server supports: ' . implode(', ', $authMethods);
            }

        } finally {
            // Send QUIT and close
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
        }

        return $result;
    }

    /**
     * Read SMTP response from socket.
     */
    private function readSmtpResponse($socket): string
    {
        $response = '';
        while ($line = fgets($socket)) {
            $response .= $line;
            // Multi-line responses end with space after code
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Check if SMTP response has success code.
     */
    private function isSuccessCode(string $response, int $expectedCode): bool
    {
        return strpos($response, (string) $expectedCode) === 0;
    }

    public function show()
    {
        $currency = Setting::getValue('currency', ['code' => 'USD']);
        $tax = Setting::getValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive', // exclusive | inclusive
            'default_tax_id' => null,
            'default_tax_rate' => null,
            'default_tax_name' => null
        ]);
        
        $mail = Setting::getValue('mail_config', [
            'driver' => 'smtp',
            'host' => 'smtp.gmail.com',
            'port' => '587',
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => 'noreply@example.com',
            'from_name' => 'NoovaERP',
        ]);

        // Mask password for security
        $mail['password'] = !empty($mail['password']) ? '********' : '';

        $defaultTax = null;
        if (!empty($tax['default_tax_id'])) {
            $defaultTax = Tax::query()->whereKey($tax['default_tax_id'])->first();
        }

        $tax['default_tax_rate'] = $defaultTax?->rate ?? 0;
        $tax['default_tax_name'] = $defaultTax?->name ?? 'None';

        return response()->json([
            'currency' => $currency,
            'tax' => $tax,
            'mail' => $mail,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'currency' => 'nullable|array',
            'currency.code' => 'nullable|string|max:10',

            'tax' => 'nullable|array',
            'tax.enabled' => 'nullable|boolean',
            'tax.pricing_mode' => 'nullable|in:exclusive,inclusive',
            'tax.default_tax_id' => 'nullable|integer',

            'mail' => 'nullable|array',
            'mail.driver' => 'nullable|string',
            'mail.host' => 'nullable|string',
            'mail.port' => 'nullable|integer',
            'mail.encryption' => 'nullable|string',
            'mail.username' => 'nullable|string',
            'mail.password' => 'nullable|string',
            'mail.from_address' => 'nullable|email',
            'mail.from_name' => 'nullable|string',
        ]);

        if (array_key_exists('currency', $validated)) {
            Setting::setValue('currency', array_merge(['code' => 'USD'], $validated['currency'] ?? []));
        }

        if (array_key_exists('tax', $validated)) {
            Setting::setValue('tax', array_merge([
                'enabled' => true,
                'pricing_mode' => 'exclusive',
                'default_tax_id' => null,
            ], $validated['tax'] ?? []));
        }

        if (array_key_exists('mail', $validated)) {
            $defaultMail = [
                'driver' => 'smtp',
                'host' => 'smtp.gmail.com',
                'port' => '587',
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_address' => 'noreply@example.com',
                'from_name' => 'NoovaERP',
            ];
            
            $existingConfig = Setting::getValue('mail_config', $defaultMail);
            $inputConfig = $validated['mail'] ?? [];

            // Password handling: if empty or masked, keep the old password
            if (empty($inputConfig['password']) || $inputConfig['password'] === '********') {
                $inputConfig['password'] = $existingConfig['password'] ?? '';
            }

            // Ensure structure is maintained by merging with default first, then existing, then input
            // Actually, merging existing with input is safer to preserve other fields
            $finalConfig = array_merge($existingConfig, $inputConfig);
            
            // Ensure all keys from default exist if they were missing in existing/input
            $finalConfig = array_merge($defaultMail, $finalConfig);

            Setting::setValue('mail_config', $finalConfig);
        }

        return $this->show();
    }
}
