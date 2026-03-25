<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_links', function (Blueprint $table) {
            $table->id();
            $table->string('source_document_type', 50);
            $table->unsignedBigInteger('source_document_id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('original_journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->string('relation_type', 30)->default('posting'); // posting, reversal, repost
            $table->timestamps();

            $table->index(['source_document_type', 'source_document_id'], 'jel_source_idx');
            $table->index('journal_entry_id');
            $table->index('original_journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_links');
    }
};
