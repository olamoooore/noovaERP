<?php

namespace Modules\AI\Functions;

use Illuminate\Support\Facades\Log;
use Modules\AI\Models\AIConversation;
use Modules\AI\Services\TicketService;

/**
 * Ticket Functions
 *
 * AI-callable functions for ticket creation
 */
class TicketFunctions
{
    /**
     * Create a support ticket
     *
     * @param  array  $args  Arguments with subject, description, priority, category
     * @param  int  $customerId  Customer ID from context
     * @param  int  $conversationId  Conversation ID from context
     * @return array Ticket creation result
     */
    public static function create(array $args, int $customerId, int $conversationId): array
    {
        try {
            $subject = $args['subject'];
            $description = $args['description'];
            $priority = $args['priority'] ?? 'medium';
            $category = $args['category'] ?? 'other';

            // Get conversation for context
            $conversation = AIConversation::with('messages')->find($conversationId);

            // Prepare conversation history (last 10 messages)
            $conversationHistory = [];
            if ($conversation && $conversation->messages) {
                $recentMessages = $conversation->messages()
                    ->orderBy('created_at', 'desc')
                    ->limit(10)
                    ->get()
                    ->reverse();

                foreach ($recentMessages as $message) {
                    $conversationHistory[] = [
                        'role' => $message->role,
                        'content' => $message->content,
                        'timestamp' => $message->created_at->toISOString(),
                    ];
                }
            }

            // Generate AI summary for admin
            $aiSummary = self::generateAISummary(
                $conversation,
                $conversationHistory,
                $subject,
                $description,
                $category
            );

            // Prepare metadata with AI summary
            $metadata = [
                'ai_module' => $conversation->active_module ?? 'general',
                'conversation_status' => $conversation->status ?? 'active',
                'ai_summary' => $aiSummary, // Add AI-generated summary
            ];

            // Create ticket via TicketService
            $ticketService = app(TicketService::class);

            $ticketData = $ticketService->prepareTicketData(
                $customerId,
                $conversationId,
                $subject,
                $description,
                $priority,
                $category,
                $conversationHistory,
                $metadata
            );

            $result = $ticketService->createTicket($ticketData);

            if ($result['success']) {
                Log::info('Ticket created via AI', [
                    'ticket_number' => $result['ticket_number'],
                    'customer_id' => $customerId,
                    'conversation_id' => $conversationId,
                ]);

                return [
                    'success' => true,
                    'ticket_number' => $result['ticket_number'],
                    'ticket_id' => $result['ticket_id'],
                    'status' => $result['status'],
                    'estimated_response_time' => $result['estimated_response_time'],
                    'message' => "Support ticket {$result['ticket_number']} has been created. Our support team will contact you within {$result['estimated_response_time']}.",
                    'mock' => $result['mock'] ?? false,
                ];
            }

            return [
                'success' => false,
                'error' => $result['error'] ?? 'Failed to create ticket',
            ];

        } catch (\Exception $e) {
            Log::error('TicketFunctions::create failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create ticket: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Generate AI summary for admin
     *
     * @return array AI-generated summary
     */
    protected static function generateAISummary(
        AIConversation $conversation,
        array $conversationHistory,
        string $subject,
        string $description,
        string $category
    ): array {
        try {
            $customer = $conversation->customer;

            // Analyze conversation to extract key information
            $userMessages = array_filter($conversationHistory, fn ($msg) => $msg['role'] === 'user');
            $assistantMessages = array_filter($conversationHistory, fn ($msg) => $msg['role'] === 'assistant');

            // Extract attempted actions from conversation
            $attemptedActions = self::extractAttemptedActions($conversationHistory);

            // Determine why AI couldn't help
            $failureReason = self::determineFailureReason($conversationHistory, $description);

            // Generate recommendation
            $recommendation = self::generateRecommendation($category, $failureReason, $description);

            // Build structured summary
            return [
                'customer_info' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'email' => $customer->email ?? 'N/A',
                ],
                'issue_summary' => [
                    'subject' => $subject,
                    'category' => $category,
                    'description' => $description,
                ],
                'conversation_analysis' => [
                    'total_messages' => count($conversationHistory),
                    'user_messages' => count($userMessages),
                    'ai_responses' => count($assistantMessages),
                    'active_module' => $conversation->active_module ?? 'general',
                ],
                'ai_actions_taken' => $attemptedActions,
                'failure_reason' => $failureReason,
                'recommended_action' => $recommendation,
                'priority_suggestion' => self::suggestPriority($failureReason, $category),
                'formatted_summary' => self::formatSummaryForAdmin(
                    $customer,
                    $subject,
                    $description,
                    $category,
                    $attemptedActions,
                    $failureReason,
                    $recommendation
                ),
            ];

        } catch (\Exception $e) {
            Log::error('Failed to generate AI summary', [
                'error' => $e->getMessage(),
            ]);

            // Return basic summary if AI generation fails
            return [
                'customer_info' => [
                    'id' => $conversation->customer_id,
                    'name' => $conversation->customer->name ?? 'Unknown',
                ],
                'issue_summary' => [
                    'subject' => $subject,
                    'description' => $description,
                ],
                'error' => 'AI summary generation failed',
            ];
        }
    }

    /**
     * Extract attempted actions from conversation
     */
    protected static function extractAttemptedActions(array $conversationHistory): array
    {
        $actions = [];

        foreach ($conversationHistory as $message) {
            if ($message['role'] === 'assistant') {
                $content = strtolower($message['content']);

                // Detect function calls or attempts
                if (str_contains($content, 'check') || str_contains($content, 'search') || str_contains($content, 'find')) {
                    if (str_contains($content, 'order')) {
                        $actions[] = 'Searched for order information';
                    } elseif (str_contains($content, 'point') || str_contains($content, 'loyalty')) {
                        $actions[] = 'Checked loyalty points';
                    } elseif (str_contains($content, 'product')) {
                        $actions[] = 'Searched product catalog';
                    } elseif (str_contains($content, 'invoice')) {
                        $actions[] = 'Looked up invoice details';
                    }
                }

                if (str_contains($content, 'couldn\'t find') || str_contains($content, 'not found') || str_contains($content, 'no results')) {
                    $actions[] = 'Search returned no results';
                }
            }
        }

        return array_unique($actions);
    }

    /**
     * Determine why AI couldn't help
     */
    protected static function determineFailureReason(array $conversationHistory, string $description): string
    {
        $description = strtolower($description);

        // Check for common failure patterns
        if (str_contains($description, 'not found') || str_contains($description, 'cannot find')) {
            return 'Data not found in system';
        }

        if (str_contains($description, 'complex') || str_contains($description, 'multiple')) {
            return 'Issue too complex for AI';
        }

        if (str_contains($description, 'human') || str_contains($description, 'agent')) {
            return 'Customer requested human support';
        }

        if (str_contains($description, 'error') || str_contains($description, 'failed')) {
            return 'System error or data issue';
        }

        return 'AI unable to resolve issue';
    }

    /**
     * Generate recommendation for admin
     */
    protected static function generateRecommendation(string $category, string $failureReason, string $description): string
    {
        $recommendations = [
            'orders' => [
                'Data not found in system' => 'Verify order exists in database. Check for data sync issues.',
                'default' => 'Review order details and contact customer for clarification.',
            ],
            'loyalty' => [
                'Data not found in system' => 'Check loyalty account status. Verify points calculation.',
                'default' => 'Review loyalty program rules and customer account.',
            ],
            'products' => [
                'Data not found in system' => 'Verify product exists in catalog. Check inventory sync.',
                'default' => 'Review product information and availability.',
            ],
            'invoices' => [
                'Data not found in system' => 'Verify invoice exists. Check payment processing status.',
                'default' => 'Review invoice details and payment history.',
            ],
            'account' => [
                'default' => 'Review customer account settings and permissions.',
            ],
        ];

        $categoryRecs = $recommendations[$category] ?? ['default' => 'Review issue and contact customer.'];

        return $categoryRecs[$failureReason] ?? $categoryRecs['default'];
    }

    /**
     * Suggest priority based on failure reason
     */
    protected static function suggestPriority(string $failureReason, string $category): string
    {
        if (str_contains($failureReason, 'error') || str_contains($failureReason, 'System')) {
            return 'high';
        }

        if ($category === 'invoices' || $category === 'orders') {
            return 'medium';
        }

        return 'medium';
    }

    /**
     * Format summary for admin viewing
     */
    protected static function formatSummaryForAdmin(
        $customer,
        string $subject,
        string $description,
        string $category,
        array $attemptedActions,
        string $failureReason,
        string $recommendation
    ): string {
        $summary = "📋 TICKET SUMMARY FOR ADMIN\n\n";
        $summary .= "Customer: {$customer->name} (ID: {$customer->id})\n";
        $summary .= 'Email: '.($customer->email ?? 'N/A')."\n";
        $summary .= "Issue: {$subject}\n";
        $summary .= 'Category: '.ucfirst($category)."\n\n";

        $summary .= "Problem Description:\n";
        $summary .= $description."\n\n";

        if (! empty($attemptedActions)) {
            $summary .= "AI Actions Taken:\n";
            foreach ($attemptedActions as $action) {
                $summary .= "- {$action}\n";
            }
            $summary .= "\n";
        }

        $summary .= "Why AI Couldn't Help:\n";
        $summary .= $failureReason."\n\n";

        $summary .= "Recommended Action:\n";
        $summary .= $recommendation."\n";

        return $summary;
    }
}
