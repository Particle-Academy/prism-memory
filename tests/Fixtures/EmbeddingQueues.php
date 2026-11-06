<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EmbeddingQueues
{
    public const DEFAULT_DB = 'embedding_default_db';

    public const CONFIGURED_DB = 'embedding_configured_db';

    public const OVERRIDE_DB = 'embedding_override_db';

    public static function configure(): void
    {
        foreach ([
            'memory-default' => self::DEFAULT_DB,
            'memory-configured' => self::CONFIGURED_DB,
            'memory-override' => self::OVERRIDE_DB,
        ] as $queue => $database) {
            config()->set('database.connections.'.$database, [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ]);
            Schema::connection($database)->create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
            config()->set('queue.connections.'.$queue, [
                'driver' => 'database', 'connection' => $database, 'table' => 'jobs',
                'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
            ]);
        }

        config()->set('queue.default', 'memory-default');
    }

    public static function count(string $database, ?string $queue = null): int
    {
        $jobs = DB::connection($database)->table('jobs');

        return ($queue === null ? $jobs : $jobs->where('queue', $queue))->count();
    }

    public static function payload(string $database): string
    {
        return (string) DB::connection($database)->table('jobs')->value('payload');
    }
}
