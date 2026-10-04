<?php

declare(strict_types=1);

namespace App\Plugins;

/**
 * Application factory base backed by the canonical ModelFactory lifecycle.
 *
 * @template TModel of \App\Database\Model
 * @extends \App\Database\Factories\ModelFactory<TModel>
 */
abstract class ModelFactory extends \App\Database\Factories\ModelFactory
{
}
