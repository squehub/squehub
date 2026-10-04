<?php

declare(strict_types=1);

namespace App\Api\Contract;

use App\Api\ApiResource;
use Closure;

/** Small application-facing gateway; mutable registrations live per Application. */
final class Contract
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): ContractManager
    {
        if (self::$resolver === null) {
            throw new ContractException('Application Contracts are unavailable before bootstrap.');
        }
        return (self::$resolver)();
    }

    public static function operation(?string $id = null): OperationContract
    {
        return new OperationContract($id);
    }

    /** Cases are registered explicitly and never execute at declaration time. */
    public static function verify(string $name): VerificationCase
    {
        return self::manager()->verify($name);
    }

    public static function schema(string $name, Schema $schema): void
    {
        self::manager()->schema($name, $schema);
    }

    public static function tag(string $name, string $description): void
    {
        self::manager()->tag($name, $description);
    }

    /** @param class-string<ApiResource> $resourceClass */
    public static function resource(string $name, string $resourceClass): void
    {
        self::manager()->resource($name, $resourceClass);
    }

    public static function webhook(string $type, Schema $dataSchema,
        ?string $description = null): void
    {
        self::manager()->webhook($type, $dataSchema, $description);
    }

    public static function info(string $title, string $version,
        ?string $description = null): void
    {
        self::manager()->info($title, $version, $description);
    }

    public static function server(string $url): void { self::manager()->server($url); }

    public static function application(?string $apiVersion = null): array
    {
        return self::manager()->application($apiVersion);
    }

    public static function openApi(?string $apiVersion = null): array
    {
        return self::manager()->openApi($apiVersion);
    }
}
