<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Operational failed-job storage shared by persistent Queue drivers. Listings
 * contain safe metadata only; retained payloads are accessible only to retry.
 */
interface FailedQueueDriver
{
    /** @return list<array{id:int,queue:string,job_class:string,error_type:string,attempts:int,failed_at:string}> */
    public function failed(int $limit = 100): array;
    public function retry(int $id): bool;
    public function forget(int $id): bool;
    public function prune(int $hours = 168): int;
}
