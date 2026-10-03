<?php

declare(strict_types=1);

namespace App\Plugins;

/** Opt-in delivery convenience for an application Model or ordinary object. */
trait Notifiable
{
    use \App\Notifications\Notifiable;
}
