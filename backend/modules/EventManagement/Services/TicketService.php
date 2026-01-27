<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingAttendee;
use Modules\EventManagement\Services\BookingFactsService;
use Modules\EventManagement\Services\OutboxService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TicketService
{
    protected TenantSchemaManager $tenantSchemaManager;
    protected AuditService $auditService;
    protected BookingFactsService $bookingFactsService;
    protected OutboxService $outboxService;

    public function __construct(
        TenantSchemaManager $tenantSchemaManager, 
        AuditService $auditService,
        BookingFactsService $bookingFactsService,
        OutboxService $outboxService
    ) {
        $this->tenantSchemaManager = $tenantSchemaManager;
        $this->auditService = $auditService;
        $this->bookingFactsService = $bookingFactsService;
        $this->outboxService = $outboxService;
    }

    /**
     * Generate tickets for a booking
     * Requirements: 3.1 - Create unique tickets for each attendee when booking reaches "Booked" state
     */
    public function generateTickets(int $bookingId): Collection
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::with(['attendees', 'event'])->findOrFail($bookingId);
            
            // Verify booking is in correct state for ticket generation
            if ($booking->state !== 'booked') {
                throw new \InvalidArgumentException("Tickets can only be generated for bookings in 'booked' state");
            }
            
            // Check if tickets already exist for this booking
            $existingTickets = Ticket::where('booking_id', $bookingId)->get();
            if ($existingTickets->isNotEmpty()) {
                throw new \InvalidArgumentException("Tickets already exist for this booking");
            }
            
            $tickets = new Collection();
            $issuanceStartTime = now();
            
            // Generate a ticket for each attendee
            foreach ($booking->attendees as $attendee) {
                $ticketCode = $this->generateUniqueCode();
                $qrPayload = $this->createQRPayload([
                    'ticket_code' => $ticketCode,
                    'booking_reference' => $booking->booking_reference,
                    'event_id' => $booking->event_id,
                    'attendee_id' => $attendee->id,
                    'attendee_name' => $attendee->attendee_name,
                    'event_title' => $booking->event->title,
                    'event_date' => $booking->event->event_date->toISOString(),
                    'venue' => $booking->event->venue
                ]);
                
                $ticket = Ticket::create([
                    'ticket_code' => $ticketCode,
                    'booking_id' => $bookingId,
                    'attendee_id' => $attendee->id,
                    'qr_payload' => $qrPayload,
                    'status' => 'active',
                    'issued_at' => Carbon::now(),
                ]);
                
                $tickets->push($ticket);
                
                // Log ticket generation
                $this->auditService->logActivity(
                    'ticket_generated',
                    'ticket',
                    $ticket->id,
                    [
                        'ticket_code' => $ticketCode,
                        'booking_reference' => $booking->booking_reference,
                        'attendee_name' => $attendee->attendee_name
                    ]
                );
            }
            
            // Capture ticket issuance facts
            $issuanceEndTime = now();
            $issuanceDelayMinutes = $issuanceStartTime->diffInMinutes($booking->created_at);
            
            $this->bookingFactsService->createAnalyticsFact('ticket_issued', $booking, [
                'tickets_issued' => $tickets->count(),
                'ticket_codes' => $tickets->pluck('ticket_code')->toArray(),
                'issuance_delay_minutes' => $issuanceDelayMinutes,
                'issuance_duration_ms' => $issuanceStartTime->diffInMilliseconds($issuanceEndTime),
                'booking_to_issuance_hours' => $booking->created_at->diffInHours($issuanceStartTime),
            ]);
            
            // Create outbox event for ticket issuance side effects
            $this->outboxService->createEvent(
                'booking',
                (string) $booking->id,
                'ticket.issued',
                [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'tickets_issued' => $tickets->count(),
                    'ticket_codes' => $tickets->pluck('ticket_code')->toArray(),
                    'issuance_delay_minutes' => $issuanceDelayMinutes,
                ]
            );
            
            return $tickets;
        });
    }

    /**
     * Generate unique ticket code
     * Requirements: 3.2 - Generate unique ticket codes that cannot be duplicated
     */
    public function generateUniqueCode(): string
    {
        do {
            // Generate a ticket code with format: TK + 8 random alphanumeric characters
            $code = 'TK' . strtoupper(Str::random(8));
        } while (Ticket::where('ticket_code', $code)->exists());

        return $code;
    }

    /**
     * Create QR payload for ticket
     * Requirements: 3.3 - Create QR/barcode payloads for each ticket
     * 
     * Note: This method creates the payload data. For actual QR code generation,
     * integrate with SimpleSoftwareIO/simple-qrcode package:
     * composer require simplesoftwareio/simple-qrcode
     */
    public function createQRPayload(array $ticketData): string
    {
        // Create a structured payload with ticket information
        $payload = [
            'version' => '1.0',
            'type' => 'event_ticket',
            'ticket_code' => $ticketData['ticket_code'],
            'booking_reference' => $ticketData['booking_reference'],
            'event_id' => $ticketData['event_id'],
            'attendee_id' => $ticketData['attendee_id'],
            'attendee_name' => $ticketData['attendee_name'],
            'event_title' => $ticketData['event_title'],
            'event_date' => $ticketData['event_date'],
            'venue' => $ticketData['venue'],
            'issued_at' => Carbon::now()->toISOString(),
            // Add checksum for verification
            'checksum' => $this->generateChecksum($ticketData)
        ];
        
        // Return JSON encoded payload
        // In production, this would be used with QR code library:
        // QrCode::format('png')->size(300)->generate(json_encode($payload))
        return json_encode($payload);
    }

    /**
     * Reissue a ticket
     * Requirements: 3.4 - Revoke old ticket and create new one when admin requests reissuance
     */
    public function reissueTicket(int $ticketId): Ticket
    {
        return DB::transaction(function () use ($ticketId) {
            $oldTicket = Ticket::with(['booking', 'attendee'])->findOrFail($ticketId);
            
            // Verify ticket can be reissued
            if ($oldTicket->status === 'revoked') {
                throw new \InvalidArgumentException("Cannot reissue an already revoked ticket");
            }
            
            if ($oldTicket->status === 'used') {
                throw new \InvalidArgumentException("Cannot reissue a used ticket");
            }
            
            // Revoke the old ticket
            $oldTicket->update([
                'status' => 'revoked',
                'revoked_at' => Carbon::now()
            ]);
            
            // Generate new ticket
            $newTicketCode = $this->generateUniqueCode();
            $qrPayload = $this->createQRPayload([
                'ticket_code' => $newTicketCode,
                'booking_reference' => $oldTicket->booking->booking_reference,
                'event_id' => $oldTicket->booking->event_id,
                'attendee_id' => $oldTicket->attendee_id,
                'attendee_name' => $oldTicket->attendee->attendee_name,
                'event_title' => $oldTicket->booking->event->title,
                'event_date' => $oldTicket->booking->event->event_date->toISOString(),
                'venue' => $oldTicket->booking->event->venue
            ]);
            
            $newTicket = Ticket::create([
                'ticket_code' => $newTicketCode,
                'booking_id' => $oldTicket->booking_id,
                'attendee_id' => $oldTicket->attendee_id,
                'qr_payload' => $qrPayload,
                'status' => 'active',
                'issued_at' => Carbon::now(),
            ]);
            
            // Log ticket reissuance
            $this->auditService->logActivity(
                'ticket_reissued',
                'ticket',
                $newTicket->id,
                [
                    'old_ticket_code' => $oldTicket->ticket_code,
                    'new_ticket_code' => $newTicketCode,
                    'booking_reference' => $oldTicket->booking->booking_reference,
                    'reason' => 'admin_reissue'
                ]
            );
            
            return $newTicket;
        });
    }

    /**
     * Revoke a ticket
     * Requirements: 3.5 - Maintain ticket validity status (active, revoked, used)
     */
    public function revokeTicket(int $ticketId): Ticket
    {
        return DB::transaction(function () use ($ticketId) {
            $ticket = Ticket::with(['booking', 'attendee'])->findOrFail($ticketId);
            
            // Verify ticket can be revoked
            if ($ticket->status === 'revoked') {
                throw new \InvalidArgumentException("Ticket is already revoked");
            }
            
            if ($ticket->status === 'used') {
                throw new \InvalidArgumentException("Cannot revoke a used ticket");
            }
            
            // Revoke the ticket
            $ticket->update([
                'status' => 'revoked',
                'revoked_at' => Carbon::now()
            ]);
            
            // Log ticket revocation
            $this->auditService->logActivity(
                'ticket_revoked',
                'ticket',
                $ticket->id,
                [
                    'ticket_code' => $ticket->ticket_code,
                    'booking_reference' => $ticket->booking->booking_reference,
                    'attendee_name' => $ticket->attendee->attendee_name
                ]
            );
            
            return $ticket;
        });
    }

    /**
     * Mark ticket as used
     */
    public function markTicketAsUsed(int $ticketId): Ticket
    {
        return DB::transaction(function () use ($ticketId) {
            $ticket = Ticket::with(['booking', 'attendee'])->findOrFail($ticketId);
            
            // Verify ticket can be used
            if ($ticket->status !== 'active') {
                throw new \InvalidArgumentException("Only active tickets can be marked as used");
            }
            
            // Mark as used
            $ticket->update([
                'status' => 'used',
                'used_at' => Carbon::now()
            ]);
            
            // Log ticket usage
            $this->auditService->logActivity(
                'ticket_used',
                'ticket',
                $ticket->id,
                [
                    'ticket_code' => $ticket->ticket_code,
                    'booking_reference' => $ticket->booking->booking_reference,
                    'attendee_name' => $ticket->attendee->attendee_name
                ]
            );
            
            return $ticket;
        });
    }

    /**
     * Get ticket by code
     */
    public function getTicketByCode(string $ticketCode): Ticket
    {
        return Ticket::with(['booking.event', 'attendee'])
            ->where('ticket_code', $ticketCode)
            ->firstOrFail();
    }

    /**
     * Get tickets for a booking
     */
    public function getBookingTickets(int $bookingId): Collection
    {
        return Ticket::with(['attendee'])
            ->where('booking_id', $bookingId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Validate ticket
     */
    public function validateTicket(string $ticketCode): array
    {
        try {
            $ticket = $this->getTicketByCode($ticketCode);
            
            return [
                'valid' => $ticket->status === 'active',
                'status' => $ticket->status,
                'ticket' => $ticket,
                'event' => $ticket->booking->event,
                'attendee' => $ticket->attendee,
                'message' => $this->getTicketStatusMessage($ticket->status)
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'status' => 'not_found',
                'ticket' => null,
                'event' => null,
                'attendee' => null,
                'message' => 'Ticket not found'
            ];
        }
    }

    /**
     * Generate checksum for ticket verification
     */
    private function generateChecksum(array $ticketData): string
    {
        // Create a checksum based on key ticket data
        $checksumData = [
            $ticketData['ticket_code'],
            $ticketData['booking_reference'],
            $ticketData['event_id'],
            $ticketData['attendee_id']
        ];
        
        return hash('sha256', implode('|', $checksumData));
    }

    /**
     * Get human-readable ticket status message
     */
    private function getTicketStatusMessage(string $status): string
    {
        return match ($status) {
            'active' => 'Ticket is valid and ready for use',
            'revoked' => 'Ticket has been revoked and is no longer valid',
            'used' => 'Ticket has already been used for entry',
            default => 'Unknown ticket status'
        };
    }
}