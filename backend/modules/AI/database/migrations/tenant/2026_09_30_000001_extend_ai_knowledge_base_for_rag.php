<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend the (previously unused) ai_knowledge_base table into the
     * platform's retrieval index: a Postgres tsvector over question+answer
     * plus ranking metadata, so chats can ground answers in tenant knowledge.
     */
    public function up(): void
    {
        Schema::table('ai_knowledge_base', function (Blueprint $table) {
            $table->integer('priority')->default(0);
            $table->string('source', 50)->nullable();
        });

        DB::statement(
            'ALTER TABLE ai_knowledge_base ADD COLUMN search_vector tsvector '.
            "GENERATED ALWAYS AS (to_tsvector('english', coalesce(question, '') || ' ' || coalesce(answer, ''))) STORED"
        );
        DB::statement('CREATE INDEX ai_knowledge_base_search_vector_idx ON ai_knowledge_base USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ai_knowledge_base_search_vector_idx');
        DB::statement('ALTER TABLE ai_knowledge_base DROP COLUMN IF EXISTS search_vector');

        Schema::table('ai_knowledge_base', function (Blueprint $table) {
            $table->dropColumn(['priority', 'source']);
        });
    }
};
