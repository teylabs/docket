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
        $schema = Schema::connection($this->getConnection());

        $schema->create('docket_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('token', 64)->unique();
            $table->string('name')->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedBigInteger('revision')->default(1);
            $table->string('step')->nullable();
            $table->unsignedBigInteger('done')->default(0);
            $table->unsignedBigInteger('total')->nullable();
            $table->unsignedBigInteger('succeeded_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('skipped_count')->default(0);
            $table->json('error')->nullable();
            $table->text('result')->nullable();
            $table->json('checkpoint')->nullable();
            $table->boolean('cancel_requested')->default(false);

            foreach (['subject', 'initiator'] as $morph) {
                $table->string($morph.'_type')->nullable();
                $table->string($morph.'_id')->nullable();
                $table->index([$morph.'_type', $morph.'_id']);
            }

            foreach (['parent_run_id', 'root_run_id', 'retry_of_run_id', 'linked_from_run_id'] as $column) {
                $table->foreignUlid($column)->nullable()->constrained('docket_runs')->nullOnDelete();
            }

            foreach (['total', 'running', 'succeeded', 'partial', 'failed'] as $status) {
                $table->unsignedBigInteger('children_'.$status)->default(0);
            }

            $table->timestamps();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });

        $schema->create('docket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('docket_runs')->cascadeOnDelete();
            $table->string('key');
            $table->string('status')->default('pending');
            $table->string('code')->nullable();
            $table->json('params')->nullable();
            $table->text('result')->nullable();
            $table->unsignedBigInteger('count')->default(1);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'key']);
            $table->index(['run_id', 'status', 'id']);
            $table->index(['run_id', 'finished_at', 'id']);
        });

        $schema->create('docket_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->constrained('docket_runs')->cascadeOnDelete();
            $table->string('code');
            $table->json('params')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->dropIfExists('docket_logs');
        $schema->dropIfExists('docket_items');
        $schema->dropIfExists('docket_runs');
    }
};
