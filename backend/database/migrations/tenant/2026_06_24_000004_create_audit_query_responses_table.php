<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_query_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('query_id')->constrained('audit_queries')->onDelete('cascade');
            $table->foreignId('responder_id')->constrained('users')->onDelete('cascade');
            $table->string('responder_role', 100);
            $table->text('response');
            $table->json('evidence_urls')->nullable();
            $table->timestamps();

            $table->index('query_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_query_responses');
    }
};
