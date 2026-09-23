<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\MailConfigLoader;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SettingsController extends Controller
{
    /**
     * Test the outbound mail configuration.
     *
     * Dispatches on `driver`:
     *   - smtp   (default): socket + AUTH handshake against the SMTP server
     *   - resend: validates the API key and sends a real test email
     *
     * Note: the historical "log mode → skip" short-circuit was removed — the
     * test must always exercise the provided credentials so a misconfigured
     * transport surfaces instead of silently reporting success.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function testSmtp(Request $request)
    {
        Log::info('Test mail endpoint hit', ['driver' => $request->input('driver', 'smtp')]);

        if (strtolower((string) $request->input('driver', 'smtp')) === MailConfigLoader::DRIVER_RESEND) {
            return $this->testResend($request);
        }

        $validated = $request->validate([
            'host' => 'required',
            'port' => 'required',
            'encryption' => 'nullable',
            'username' => 'nullable',
            'password' => 'nullable',
            'from_address' => 'nullable',
            'timeout' => 'nullable',
        ]);

        $timeout = min((int) ($validated['timeout'] ?? 5), 10); // default 5s, max 10s
        $encryption = $validated['encryption'] ?? 'tls';

        // Auto-correct port/encryption mismatch
        $port = (int) $validated['port'];
        if ($port === 465 && $encryption === 'tls') {
            $encryption = 'ssl'; // Port 465 requires implicit SSL, not STARTTLS
            Log::info("Auto-corrected encryption from 'tls' to 'ssl' for port 465");
        } elseif ($port === 587 && $encryption === 'ssl') {
            $encryption = 'tls'; // Port 587 uses STARTTLS, not implicit SSL
            Log::info("Auto-corrected encryption from 'ssl' to 'tls' for port 587");
        }

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

            if (! $socketTest['success']) {
                $result['message'] = 'Connection failed: '.$socketTest['message'];
                $result['details'] = $socketTest['details'];
                Log::error('SMTP Socket Connection Failed', $result);

                return response()->json($result, 422);
            }

            $result['details']['socket'] = 'Connected to '.$host.':'.$port;

            // Step 2: Test SMTP authentication using PHP's built-in functions
            Log::info("Testing SMTP authentication for {$username}");
            $smtpTest = $this->testSmtpAuthentication($host, $port, $username, $password, $encryption, $timeout);

            if ($smtpTest['success']) {
                $result['success'] = true;
                $result['message'] = 'SMTP connection successful! The mail server is reachable and credentials are valid.';
                $result['details']['auth'] = 'Authentication successful';
                $result['details']['server_info'] = $smtpTest['server_info'] ?? [];
            } else {
                $result['message'] = 'SMTP authentication failed: '.$smtpTest['message'];
                $result['details']['auth'] = 'Authentication failed';
                Log::error('SMTP Authentication Failed', $result);
            }

        } catch (Exception $e) {
            $result['message'] = 'SMTP test failed: '.$e->getMessage();
            $result['details']['error'] = $e->getMessage();
            Log::error('SMTP Test Error', [
                'host' => $host,
                'port' => $port,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Test the Resend transport: validate the API key and send a real email.
     */
    private function testResend(Request $request)
    {
        $validated = $request->validate([
            'api_key' => 'required|string',
            'from_address' => 'nullable|email',
            'test_recipient' => 'nullable|email',
        ]);

        $apiKey = $validated['api_key'];

        // Masked placeholder → reuse the stored key
        if ($apiKey === '********') {
            $stored = Setting::getValue('mail_config', []);
            $apiKey = is_array($stored) ? ($stored['api_key'] ?? '') : '';
        }

        if (empty($apiKey) || ! str_starts_with($apiKey, 're_')) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Resend API key. Keys start with "re_".',
                'details' => ['key' => 'Invalid format'],
            ], 422);
        }

        $recipient = $validated['test_recipient'] ?? $validated['from_address'] ?? null;
        if (! $recipient) {
            return response()->json([
                'success' => false,
                'message' => 'A test recipient is required. Set a From Address or provide test_recipient.',
                'details' => ['recipient' => 'Missing'],
            ], 422);
        }

        config([
            'mail.default' => 'resend',
            'mail.mailers.resend.key' => $apiKey,
            'services.resend.key' => $apiKey,
        ]);
        app('mail.manager')->purge('resend');

        if (! empty($validated['from_address'])) {
            config(['mail.from.address' => $validated['from_address']]);
        }

        $startedAt = microtime(true);

        try {
            Mail::mailer('resend')->raw(
                "NoovaERP mail test via Resend\n\nSent at ".now()->toDateTimeString()."\nFrom: ".config('mail.from.address'),
                function ($message) use ($recipient) {
                    $message->to($recipient)
                        ->subject('NoovaERP — Resend connectivity test');
                }
            );

            $elapsed = round((microtime(true) - $startedAt) * 1000, 2);

            Log::info('Resend mail test succeeded', ['recipient' => $recipient]);

            return response()->json([
                'success' => true,
                'message' => "Resend accepted the test email for {$recipient}. Check the inbox (and spam) to confirm delivery.",
                'details' => [
                    'driver' => 'resend',
                    'recipient' => $recipient,
                    'from_address' => config('mail.from.address'),
                    'round_trip_ms' => $elapsed,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Resend mail test failed', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Resend test failed: '.$e->getMessage(),
                'details' => [
                    'driver' => 'resend',
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                ],
            ], 422);
        }
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

        if (! $socket) {
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
        $remote = 'tcp://'.$host.':'.$port;
        if ($encryption === 'ssl') {
            $remote = 'ssl://'.$host.':'.$port;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (! $socket) {
            $result['message'] = "Could not connect: {$errstr} ({$errno})";

            return $result;
        }

        stream_set_timeout($socket, $timeout);

        try {
            // Read greeting
            $greeting = $this->readSmtpResponse($socket);
            $result['server_info']['greeting'] = trim($greeting);

            if (! $this->isSuccessCode($greeting, 220)) {
                $result['message'] = 'Invalid server greeting: '.$greeting;

                return $result;
            }

            // Send EHLO
            fwrite($socket, "EHLO localhost\r\n");
            $ehloResponse = $this->readSmtpResponse($socket);
            $result['server_info']['ehlo'] = trim($ehloResponse);

            if (! $this->isSuccessCode($ehloResponse, 250)) {
                $result['message'] = 'EHLO failed: '.$ehloResponse;

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
                    if (! stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
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
                    fwrite($socket, base64_encode($username)."\r\n");
                    $userResponse = $this->readSmtpResponse($socket);

                    if ($this->isSuccessCode($userResponse, 334)) {
                        // Send password
                        fwrite($socket, base64_encode($password)."\r\n");
                        $passResponse = $this->readSmtpResponse($socket);

                        if ($this->isSuccessCode($passResponse, 235)) {
                            $result['success'] = true;
                            $result['message'] = 'Authentication successful';
                        } else {
                            $result['message'] = 'Authentication failed (invalid credentials): '.$passResponse;
                        }
                    } else {
                        $result['message'] = 'Username rejected: '.$userResponse;
                    }
                } else {
                    $result['message'] = 'AUTH LOGIN not accepted: '.$authResponse;
                }
            } else {
                $result['message'] = 'No supported authentication methods available. Server supports: '.implode(', ', $authMethods);
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
        $currency = array_merge(
            ['code' => 'USD', 'display_format' => 'symbol'],
            Setting::getValue('currency', [])
        );
        $tax = Setting::getValue('tax', [
            'enabled' => true,
            'pricing_mode' => 'exclusive', // exclusive | inclusive
            'default_tax_id' => null,
            'default_tax_rate' => null,
            'default_tax_name' => null,
        ]);

        $mail = Setting::getValue('mail_config', [
            'driver' => 'smtp',
            'host' => 'smtpout.secureserver.net',
            'port' => '587',
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'api_key' => '',
            'from_address' => 'noreply@example.com',
            'from_name' => 'NoovaERP',
        ]);

        // Mask secrets for security
        $mail['password'] = ! empty($mail['password']) ? '********' : '';
        $mail['api_key'] = ! empty($mail['api_key']) ? '********' : '';

        $defaultTax = null;
        if (! empty($tax['default_tax_id'])) {
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
            'currency.display_format' => 'nullable|in:symbol,narrow_symbol,code',

            'tax' => 'nullable|array',
            'tax.enabled' => 'nullable|boolean',
            'tax.pricing_mode' => 'nullable|in:exclusive,inclusive',
            'tax.default_tax_id' => 'nullable|integer',

            'mail' => 'nullable|array',
            'mail.driver' => 'nullable|in:smtp,resend,log,array,sendmail',
            'mail.host' => 'nullable|string',
            'mail.port' => 'nullable|integer',
            'mail.encryption' => 'nullable|string',
            'mail.username' => 'nullable|string',
            'mail.password' => 'nullable|string',
            'mail.api_key' => 'nullable|string',
            'mail.from_address' => 'nullable|email',
            'mail.from_name' => 'nullable|string',
        ]);

        if (array_key_exists('currency', $validated)) {
            $existingCurrency = Setting::getValue('currency', []);
            Setting::setValue('currency', array_merge(
                ['code' => 'USD', 'display_format' => 'symbol'],
                $existingCurrency,
                $validated['currency'] ?? []
            ));
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
                'host' => 'smtpout.secureserver.net',
                'port' => '587',
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'api_key' => '',
                'from_address' => 'noreply@example.com',
                'from_name' => 'NoovaERP',
            ];

            $existingConfig = Setting::getValue('mail_config', $defaultMail);
            $inputConfig = $validated['mail'] ?? [];

            // Secret handling: if empty or masked, keep the previously stored secret
            if (empty($inputConfig['password']) || $inputConfig['password'] === '********') {
                $inputConfig['password'] = $existingConfig['password'] ?? '';
            }
            if (empty($inputConfig['api_key']) || $inputConfig['api_key'] === '********') {
                $inputConfig['api_key'] = $existingConfig['api_key'] ?? '';
            }

            // Auto-correct port/encryption mismatch (SMTP only)
            $port = (int) ($inputConfig['port'] ?? $existingConfig['port'] ?? 587);
            $enc = $inputConfig['encryption'] ?? $existingConfig['encryption'] ?? 'tls';
            if ($port === 465 && $enc === 'tls') {
                $inputConfig['encryption'] = 'ssl';
            } elseif ($port === 587 && $enc === 'ssl') {
                $inputConfig['encryption'] = 'tls';
            }

            // Ensure structure is maintained by merging with default first, then existing, then input
            $finalConfig = array_merge($existingConfig, $inputConfig);

            // Ensure all keys from default exist if they were missing in existing/input
            $finalConfig = array_merge($defaultMail, $finalConfig);

            Setting::setValue('mail_config', $finalConfig);
        }

        return $this->show();
    }
}
