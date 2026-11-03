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
            $table->json('output')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasColumn('docket_runs', 'output')) {
            $schema->table('docket_runs', function (Blueprint $table) {
                $table->dropColumn('output');
            });
        }
    }
};
