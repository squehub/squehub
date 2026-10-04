<?php

declare(strict_types=1);

namespace App\Observability;

/** Receives bounded local execution reports independently of export sampling. */
interface ObservationConsumer
{
    public function accept(ObservationReport $report): void;
}
