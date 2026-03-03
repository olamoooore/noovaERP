<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * FeedbackController
 *
 * Handles customer feedback submission and retrieval for event management.
 */
class FeedbackController extends Controller
{
    /**
     * Get available feedback types.
     */
    public function getFeedbackTypes(Request $request): JsonResponse
    {
        $types = [
            ['id' => 'event_quality',   'label' => 'Event Quality',   'description' => 'Overall event experience'],
            ['id' => 'venue',           'label' => 'Venue',           'description' => 'Venue facilities and environment'],
            ['id' => 'staff',           'label' => 'Staff',           'description' => 'Staff behaviour and helpfulness'],
            ['id' => 'ticketing',       'label' => 'Ticketing',       'description' => 'Ticketing and booking process'],
            ['id' => 'communication',   'label' => 'Communication',   'description' => 'Event communication and updates'],
            ['id' => 'value_for_money', 'label' => 'Value for Money', 'description' => 'Overall value for the price paid'],
            ['id' => 'general',         'label' => 'General',         'description' => 'General feedback'],
        ];

        return response()->json([
            'success' => true,
            'data'    => $types,
        ]);
    }

    /**
     * Get available rating categories.
     */
    public function getRatingCategories(Request $request): JsonResponse
    {
        $categories = [
            ['id' => 'overall',       'label' => 'Overall Experience', 'min' => 1, 'max' => 5],
            ['id' => 'entertainment', 'label' => 'Entertainment',      'min' => 1, 'max' => 5],
            ['id' => 'organisation',  'label' => 'Organisation',       'min' => 1, 'max' => 5],
            ['id' => 'venue',         'label' => 'Venue & Facilities', 'min' => 1, 'max' => 5],
            ['id' => 'value',         'label' => 'Value for Money',    'min' => 1, 'max' => 5],
        ];

        return response()->json([
            'success' => true,
            'data'    => $categories,
        ]);
    }

    /**
     * Get feedback submitted by the authenticated customer.
     */
    public function getMyFeedback(Request $request): JsonResponse
    {
        try {
            $customerId = $request->user()->id;

            $feedback = [];

            if (\Schema::hasTable('event_feedback')) {
                $feedback = DB::table('event_feedback')
                    ->where('customer_id', $customerId)
                    ->orderByDesc('created_at')
                    ->get()
                    ->map(fn ($row) => (array) $row)
                    ->toArray();
            }

            return response()->json([
                'success' => true,
                'data'    => $feedback,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to load feedback',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List feedback (optionally scoped to an event).
     */
    public function getFeedback(Request $request): JsonResponse
    {
        try {
            $customerId = $request->user()->id;
            $eventId    = $request->query('event_id');

            $feedback = [];

            if (\Schema::hasTable('event_feedback')) {
                $query = DB::table('event_feedback')
                    ->where('customer_id', $customerId)
                    ->orderByDesc('created_at');

                if ($eventId) {
                    $query->where('event_id', (int) $eventId);
                }

                $feedback = $query->get()->map(fn ($row) => (array) $row)->toArray();
            }

            return response()->json([
                'success' => true,
                'data'    => $feedback,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to load feedback',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit new feedback.
     */
    public function submitFeedback(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_id'     => 'required|integer',
            'rating'       => 'required|integer|min:1|max:5',
            'comment'      => 'nullable|string|max:2000',
            'category'     => 'nullable|string|max:100',
            'is_anonymous' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $customerId = $request->user()->id;

            $id = null;

            if (\Schema::hasTable('event_feedback')) {
                $id = DB::table('event_feedback')->insertGetId([
                    'customer_id'  => $customerId,
                    'event_id'     => $request->input('event_id'),
                    'rating'       => $request->input('rating'),
                    'comment'      => $request->input('comment'),
                    'category'     => $request->input('category', 'general'),
                    'is_anonymous' => $request->boolean('is_anonymous', false),
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            return response()->json([
                'success' => true,
                'data'    => ['id' => $id] + $request->only(['event_id', 'rating', 'comment', 'category', 'is_anonymous']),
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to submit feedback',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Vote a feedback item as helpful / not helpful.
     */
    public function voteFeedback(Request $request, int $feedbackId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'helpful' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error'   => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get feedback statistics for an event.
     */
    public function getFeedbackStats(Request $request, int $eventId): JsonResponse
    {
        try {
            $stats = [
                'event_id'     => $eventId,
                'total'        => 0,
                'average'      => 0,
                'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
            ];

            if (\Schema::hasTable('event_feedback')) {
                $rows = DB::table('event_feedback')
                    ->where('event_id', $eventId)
                    ->selectRaw('COUNT(*) as total, AVG(rating) as average, rating')
                    ->groupBy('rating')
                    ->get();

                $stats['total'] = $rows->sum('total');
                $stats['average'] = $rows->sum(fn ($r) => $r->rating * $r->total) / max($stats['total'], 1);

                foreach ($rows as $row) {
                    $stats['distribution'][$row->rating] = (int) $row->total;
                }
            }

            return response()->json(['success' => true, 'data' => $stats]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => 'Failed to load feedback stats',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
