<?php

namespace Tests\Feature\Operations;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_only_expired_cache_rows_and_sessions(): void
    {
        config(['cache.default' => 'database', 'session.driver' => 'database', 'session.lifetime' => 120]);
        $now = now()->getTimestamp();

        DB::table('cache')->insert([
            ['key' => 'vencida', 'value' => 'x', 'expiration' => $now - 10],
            ['key' => 'valida', 'value' => 'x', 'expiration' => $now + 600],
        ]);
        DB::table('cache_locks')->insert([
            ['key' => 'trava-vencida', 'owner' => 'a', 'expiration' => $now - 10],
            ['key' => 'trava-valida', 'owner' => 'b', 'expiration' => $now + 600],
        ]);
        DB::table('sessions')->insert([
            ['id' => 'antiga', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => $now - 121 * 60],
            ['id' => 'ativa', 'user_id' => null, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => $now - 60],
        ]);

        $this->artisan('clinic:prune')->expectsOutputToContain('Linhas expiradas removidas: 3')->assertSuccessful();

        $this->assertSame(['valida'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['trava-valida'], DB::table('cache_locks')->pluck('key')->all());
        $this->assertSame(['ativa'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_it_leaves_tables_alone_when_other_drivers_are_in_use(): void
    {
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        DB::table('cache')->insert(['key' => 'vencida', 'value' => 'x', 'expiration' => now()->getTimestamp() - 10]);

        $this->artisan('clinic:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('cache')->count());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'clinic:prune'));

        $this->assertNotNull($event);
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertSame(config('clinic.timezone'), $event->timezone);
    }
}
