<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QueueFailingListenerTest extends TestCase
{
    public function test_failing_listener_logs_failed_jobs(): void
    {
        $logged = null;
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            if ($event->level === 'error') {
                $logged = ['message' => $event->message, 'context' => $event->context];
            }
        });

        $job = $this->mockQueueJob('App\\Jobs\\SomeOtherJob');
        $exception = new \RuntimeException('other job blew up');

        Event::dispatch(new JobFailed('sync', $job, $exception));

        $this->assertNotNull($logged, 'Expected an error log entry');
        $this->assertSame('App\\Jobs\\SomeOtherJob', $logged['context']['job']);
        $this->assertSame('other job blew up', $logged['context']['error']);
        $this->assertSame(\RuntimeException::class, $logged['context']['class']);
    }

    private function mockQueueJob(string $resolvedName): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('resolveName')->willReturn($resolvedName);

        return $job;
    }
}
