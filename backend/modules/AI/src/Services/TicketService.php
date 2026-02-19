<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ticket Service
 * 
 * Handles ticket creation via Ticket Module API
 */
class TicketService
{
    protected string $ticketApiUrl;
    protected bool $useMock;

    public function __construct()
    {
        // Get Ticket Module API URL from config
        $this->ticketApiUrl = config('services.ticket.url', 'http://localhost/api/v1/tickets');
        
        // Use mock if Ticket Module is not available yet
        $this->useMock = config('services.ticket.use_mock', true);
    }

    /**
     * Create a support ticket
     * 
     * @param array $data Ticket data
     * @return array Result with ticket information
     */
    public function createTicket(array $data): array
    {
        // If using mock, return mock response
        if ($this->useMock) {
            return $this->createMockTicket($data);
        }

        try {
            // Call Ticket Module API
            $response = Http::withToken(auth()->user()->currentAccessToken()->plainTextToken)
                ->timeout(10)
                ->post($this->ticketApiUrl, $data);

            if ($response->successful()) {
                $result = $response->json();
                
                Log::info('Ticket created successfully', [
                    'ticket_id' => $result['data']['ticket_id'] ?? null,
                    'ticket_number' => $result['data']['ticket_number'] ?? null,
                ]);

                return [
                    'success' => true,
                    'ticket_id' => $result['data']['ticket_id'] ?? null,
                    'ticket_number' => $result['data']['ticket_number'] ?? null,
                    'status' => $result['data']['status'] ?? 'open',
                    'created_at' => $result['data']['created_at'] ?? now()->toISOString(),
                    'estimated_response_time' => $result['data']['estimated_response_time'] ?? '2 hours',
                ];
            }

            // API call failed
            Log::error('Ticket API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create ticket: ' . $response->body(),
            ];

        } catch (\Exception $e) {
            Log::error('Ticket creation exception', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);

            return [
                'success' => false,
                'error' => 'Ticket creation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create mock ticket (for development)
     * 
     * @param array $data Ticket data
     * @return array Mock ticket response
     */
    protected function createMockTicket(array $data): array
    {
        $ticketNumber = 'TKT-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        Log::info('Mock ticket created', [
            'ticket_number' => $ticketNumber,
            'subject' => $data['subject'] ?? 'No subject',
        ]);

        return [
            'success' => true,
            'ticket_id' => rand(1000, 9999),
            'ticket_number' => $ticketNumber,
            'status' => 'open',
            'created_at' => now()->toISOString(),
            'estimated_response_time' => '2 hours',
            'mock' => true,
        ];
    }

    /**
     * Prepare ticket data from conversation
     * 
     * @param int $customerId Customer ID
     * @param int $conversationId Conversation ID
     * @param string $subject Ticket subject
     * @param string $description Ticket description
     * @param string $priority Priority level
     * @param string $category Issue category
     * @param array $conversationHistory Chat history
     * @param array $metadata Additional metadata
     * @return array Formatted ticket data
     */
    public function prepareTicketData(
        int $customerId,
        int $conversationId,
        string $subject,
        string $description,
        string $priority = 'medium',
        string $category = 'other',
        array $conversationHistory = [],
        array $metadata = []
    ): array {
        return [
            'customer_id' => $customerId,
            'conversation_id' => $conversationId,
            'subject' => substr($subject, 0, 255), // Limit to 255 chars
            'description' => $description,
            'priority' => $priority,
            'category' => $category,
            'source' => 'ai_chat',
            'conversation_history' => $conversationHistory,
            'metadata' => array_merge([
                'ai_module' => $metadata['ai_module'] ?? 'general',
                'created_via' => 'ai_chatbot',
            ], $metadata),
        ];
    }
}
