<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tey\Docket\Database\Connection;

return new class extends Migration
{
    public function getConnection(): string
    {
        return app(Connection::class)->name();
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('docket_runs', function (Blueprint $table) {
            $table->json('output_link')->nullable();
            $table->index(['name', 'status', 'created_at'], 'docket_runs_listing_active_index');
            $table->index(['name', 'finished_at'], 'docket_runs_listing_finished_index');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach (['docket_runs_listing_active_index', 'docket_runs_listing_finished_index'] as $index) {
            if ($schema->hasTable('docket_runs') && $schema->hasIndex('docket_runs', $index)) {
                $schema->table('docket_runs', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            }
        }

        if ($schema->hasTable('docket_runs') && $schema->hasColumn('docket_runs', 'output_link')) {
            $schema->table('docket_runs', function (Blueprint $table) {
                $table->dropColumn('output_link');
            });
        }
    }
};
