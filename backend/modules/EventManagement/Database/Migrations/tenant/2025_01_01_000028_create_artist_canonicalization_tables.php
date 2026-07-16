<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Artist Canonicalization Tables - Task 6.1
 *
 * Creates comprehensive artist canonicalization database schema for managing
 * canonical artist names, aliases, translations, and merge audit trails.
 *
 * Requirements: 22.1, 22.4
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Artist canonical table for canonical name management
        if (! Schema::hasTable('artist_canonical')) {
            Schema::create('artist_canonical', function (Blueprint $table) {
                $table->id();
                $table->string('canonical_name', 255)->unique();
                $table->enum('canonical_type', ['artist', 'group']);
                $table->unsignedBigInteger('master_record_id');
                $table->string('canonical_slug', 255)->unique();
                $table->json('canonical_metadata')->nullable();
                $table->string('primary_genre', 100)->nullable();
                $table->string('country_origin', 3)->nullable(); // ISO 3166-1 alpha-3
                $table->date('formation_date')->nullable();
                $table->date('dissolution_date')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('created_by', 100)->nullable();
                $table->string('updated_by', 100)->nullable();
                $table->timestamps();

                // Indexes for performance
                $table->index(['canonical_name']);
                $table->index(['canonical_type', 'is_active']);
                $table->index(['master_record_id']);
                $table->index(['canonical_slug']);
                $table->index(['primary_genre']);
                $table->index(['country_origin']);
                $table->index(['is_active', 'created_at']);
            });
        }

        // Artist aliases table for name variations and translations
        if (! Schema::hasTable('artist_aliases')) {
            Schema::create('artist_aliases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('canonical_id');
                $table->string('alias_name', 255);
                $table->enum('alias_type', [
                    'stage_name',
                    'romanized',
                    'translated',
                    'former_name',
                    'nickname',
                    'legal_name',
                    'localized',
                    'abbreviated',
                ]);
                $table->string('language_code', 5)->nullable(); // ISO 639-1 with optional region
                $table->string('script_code', 4)->nullable(); // ISO 15924
                $table->boolean('is_primary')->default(false);
                $table->boolean('is_searchable')->default(true);
                $table->integer('usage_frequency')->default(0);
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                $table->json('alias_metadata')->nullable();
                $table->string('source', 100)->nullable(); // Where this alias came from
                $table->decimal('confidence_score', 3, 2)->default(1.00); // 0.00 to 1.00
                $table->string('created_by', 100)->nullable();
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('canonical_id')->references('id')->on('artist_canonical')->onDelete('cascade');

                // Unique constraint for alias name and type combination
                $table->unique(['alias_name', 'alias_type', 'language_code'], 'unique_alias_lang');

                // Indexes for search and relationship queries
                $table->index(['canonical_id']);
                $table->index(['alias_name']);
                $table->index(['alias_type']);
                $table->index(['language_code']);
                $table->index(['is_primary', 'canonical_id']);
                $table->index(['is_searchable', 'alias_name']);
                $table->index(['usage_frequency']);
                $table->index(['confidence_score']);
                $table->index(['valid_from', 'valid_until']);
            });
        }

        // Artist merge log table for merge audit trails
        if (! Schema::hasTable('artist_merge_log')) {
            Schema::create('artist_merge_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('source_artist_id');
                $table->unsignedBigInteger('target_artist_id');
                $table->string('merge_reason', 500);
                $table->json('merged_data'); // Complete snapshot of merged data
                $table->json('conflict_resolution')->nullable(); // How conflicts were resolved
                $table->json('affected_entities')->nullable(); // Events, bookings, etc. affected
                $table->enum('merge_status', ['pending', 'completed', 'failed', 'rolled_back']);
                $table->unsignedBigInteger('merged_by_user_id');
                $table->unsignedBigInteger('approved_by_user_id')->nullable();
                $table->timestamp('merge_initiated_at');
                $table->timestamp('merge_completed_at')->nullable();
                $table->text('merge_notes')->nullable();
                $table->json('rollback_data')->nullable(); // Data needed for rollback
                $table->boolean('is_reversible')->default(true);
                $table->timestamps();

                // Indexes for audit queries
                $table->index(['source_artist_id']);
                $table->index(['target_artist_id']);
                $table->index(['merge_status']);
                $table->index(['merged_by_user_id']);
                $table->index(['merge_initiated_at']);
                $table->index(['merge_completed_at']);
                $table->index(['is_reversible']);
            });
        }

        // Artist search index table for optimized search
        if (! Schema::hasTable('artist_search_index')) {
            Schema::create('artist_search_index', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('canonical_id');
                $table->string('search_term', 255);
                $table->enum('term_type', ['canonical', 'alias', 'phonetic', 'metaphone']);
                $table->string('language_code', 5)->nullable();
                $table->decimal('search_weight', 3, 2)->default(1.00);
                $table->integer('term_frequency')->default(1);
                $table->boolean('is_exact_match')->default(true);
                $table->timestamps();

                // Foreign key constraint
                $table->foreign('canonical_id')->references('id')->on('artist_canonical')->onDelete('cascade');

                // Indexes for search performance
                $table->index(['search_term']);
                $table->index(['canonical_id']);
                $table->index(['term_type']);
                $table->index(['language_code']);
                $table->index(['search_weight']);
                $table->index(['is_exact_match', 'search_term']);

                // Full-text search index
                $table->fullText(['search_term']);
            });
        }

        // Artist relationship table for group memberships and collaborations
        if (! Schema::hasTable('artist_relationships')) {
            Schema::create('artist_relationships', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('primary_artist_id');
                $table->unsignedBigInteger('related_artist_id');
                $table->enum('relationship_type', [
                    'member_of',
                    'former_member_of',
                    'founder_of',
                    'collaborator',
                    'featured_artist',
                    'producer',
                    'songwriter',
                    'manager',
                    'label_artist',
                ]);
                $table->string('role_description', 255)->nullable();
                $table->date('relationship_start')->nullable();
                $table->date('relationship_end')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('relationship_strength')->default(1); // 1-10 scale
                $table->json('relationship_metadata')->nullable();
                $table->string('source', 100)->nullable();
                $table->decimal('confidence_score', 3, 2)->default(1.00);
                $table->timestamps();

                // Foreign key constraints
                $table->foreign('primary_artist_id')->references('id')->on('artist_canonical')->onDelete('cascade');
                $table->foreign('related_artist_id')->references('id')->on('artist_canonical')->onDelete('cascade');

                // Prevent duplicate relationships
                $table->unique(['primary_artist_id', 'related_artist_id', 'relationship_type'], 'unique_artist_relationship');

                // Indexes for relationship queries
                $table->index(['primary_artist_id']);
                $table->index(['related_artist_id']);
                $table->index(['relationship_type']);
                $table->index(['is_active']);
                $table->index(['relationship_start', 'relationship_end']);
                $table->index(['relationship_strength']);
                $table->index(['confidence_score']);
            });
        }

        // Artist canonicalization queue for batch processing
        if (! Schema::hasTable('artist_canonicalization_queue')) {
            Schema::create('artist_canonicalization_queue', function (Blueprint $table) {
                $table->id();
                $table->string('operation_type', 50); // merge, alias_add, search_index_update
                $table->json('operation_data');
                $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
                $table->integer('priority')->default(5); // 1-10, higher is more urgent
                $table->integer('retry_count')->default(0);
                $table->integer('max_retries')->default(3);
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('processing_metadata')->nullable();
                $table->timestamps();

                // Indexes for queue processing
                $table->index(['status', 'priority', 'scheduled_at']);
                $table->index(['operation_type']);
                $table->index(['retry_count']);
                $table->index(['created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_canonicalization_queue');
        Schema::dropIfExists('artist_relationships');
        Schema::dropIfExists('artist_search_index');
        Schema::dropIfExists('artist_merge_log');
        Schema::dropIfExists('artist_aliases');
        Schema::dropIfExists('artist_canonical');
    }
};
