<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Phase 2 Analytics Tables.
     * Creates event_analytics, ticket_type_analytics, and daily_sales_metrics tables
     * with proper tenant isolation and time-series optimization.
     */
    public function up(): void
    {
        // Event Analytics Table - Stores KPI data per event
        Schema::create('event_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('tenant_id')->index(); // Tenant isolation
            
            // Core KPI Metrics
            $table->integer('tickets_sold')->default(0);
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('capacity_utilization', 5, 2)->default(0); // Percentage
            $table->integer('no_show_count')->default(0);
            $table->decimal('no_show_rate', 5, 2)->default(0); // Percentage
            
            // Sales Performance Metrics
            $table->decimal('average_ticket_price', 10, 2)->default(0);
            $table->integer('unique_customers')->default(0);
            $table->integer('repeat_customers')->default(0);
            $table->decimal('repeat_customer_rate', 5, 2)->default(0); // Percentage
            
            // Time-based Metrics
            $table->timestamp('first_sale_at')->nullable();
            $table->timestamp('last_sale_at')->nullable();
            $table->integer('sales_duration_hours')->default(0);
            $table->decimal('peak_sales_velocity', 8, 2)->default(0); // Tickets per hour
            
            // Metadata
            $table->json('additional_metrics')->nullable(); // For extensibility
            $table->timestamp('calculated_at');
            $table->timestamps();
            
            // Indexes for performance and tenant isolation
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'calculated_at']);
            $table->index(['capacity_utilization', 'tenant_id']);
            $table->index(['total_revenue', 'tenant_id']);
        });

        // Ticket Type Analytics Table - Performance metrics per ticket type
        Schema::create('ticket_type_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('ticket_type_id')->constrained('event_ticket_types')->onDelete('cascade');
            $table->string('tenant_id')->index(); // Tenant isolation
            
            // Sales Performance
            $table->integer('tickets_sold')->default(0);
            $table->integer('tickets_available')->default(0);
            $table->decimal('sell_through_rate', 5, 2)->default(0); // Percentage
            $table->decimal('revenue_generated', 12, 2)->default(0);
            $table->decimal('average_sale_price', 10, 2)->default(0);
            
            // Time-based Performance
            $table->timestamp('first_sale_at')->nullable();
            $table->timestamp('sold_out_at')->nullable();
            $table->integer('time_to_sell_out_hours')->nullable();
            $table->decimal('sales_velocity', 8, 2)->default(0); // Tickets per hour
            
            // Customer Insights
            $table->integer('unique_buyers')->default(0);
            $table->decimal('average_quantity_per_buyer', 5, 2)->default(0);
            $table->integer('max_quantity_per_buyer')->default(0);
            
            // Performance Rankings
            $table->integer('revenue_rank')->nullable(); // Rank among ticket types for this event
            $table->integer('velocity_rank')->nullable(); // Sales velocity rank
            
            $table->timestamp('calculated_at');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['tenant_id', 'event_id']);
            $table->index(['tenant_id', 'ticket_type_id']);
            $table->index(['sell_through_rate', 'tenant_id']);
            $table->index(['sales_velocity', 'tenant_id']);
            $table->index(['revenue_generated', 'tenant_id']);
        });

        // Daily Sales Metrics Table - Time-series data for trend analysis
        Schema::create('daily_sales_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('tenant_id')->index(); // Tenant isolation
            $table->date('metric_date');
            $table->integer('metric_hour')->nullable(); // 0-23 for hourly granularity
            
            // Daily/Hourly Sales Data
            $table->integer('tickets_sold')->default(0);
            $table->decimal('revenue_generated', 12, 2)->default(0);
            $table->integer('unique_customers')->default(0);
            $table->integer('new_customers')->default(0);
            $table->integer('returning_customers')->default(0);
            
            // Booking Patterns
            $table->integer('total_bookings')->default(0);
            $table->decimal('average_tickets_per_booking', 5, 2)->default(0);
            $table->integer('cancelled_bookings')->default(0);
            $table->decimal('cancellation_rate', 5, 2)->default(0);
            
            // Traffic and Conversion
            $table->integer('page_views')->default(0); // If tracking is available
            $table->decimal('conversion_rate', 5, 2)->default(0); // Bookings/Views
            $table->integer('abandoned_carts')->default(0);
            
            // Peak Performance Indicators
            $table->boolean('is_peak_hour')->default(false);
            $table->boolean('is_weekend')->default(false);
            $table->string('day_of_week', 10);
            
            $table->timestamps();
            
            // Indexes for time-series queries and tenant isolation
            $table->index(['tenant_id', 'event_id', 'metric_date']);
            $table->index(['tenant_id', 'metric_date', 'metric_hour']);
            $table->index(['tenant_id', 'is_peak_hour']);
            $table->index(['tenant_id', 'is_weekend']);
            $table->index(['conversion_rate', 'tenant_id']);
            
            // Unique constraint to prevent duplicate entries
            $table->unique(['event_id', 'metric_date', 'metric_hour', 'tenant_id'], 'unique_daily_metrics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_sales_metrics');
        Schema::dropIfExists('ticket_type_analytics');
        Schema::dropIfExists('event_analytics');
    }
};