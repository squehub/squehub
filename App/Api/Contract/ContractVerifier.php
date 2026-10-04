<?php

declare(strict_types=1);

namespace App\Api\Contract;

use App\Auth\Middleware\RequireAuthentication;
use App\Auth\Middleware\RequireToken;
use App\Auth\Middleware\RequireTokenAbility;
use App\Authorization\Middleware\RequireAbility;
use App\Config\Repository;
use App\Container\Container;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Routing\MiddlewareRegistry;
use App\Routing\RouteDefinition;
use App\Routing\RouteRegistry;
use JsonException;
use Throwable;

/**
 * Checks the native application contract and deliberately registered examples.
 * Static checks never invoke application handlers. Runtime checks enter the
 * ordinary Kernel and validate only the selected response against its contract.
 */
final class ContractVerifier
{
    public function __construct(
        private Application $app,
        private ContractManager $contracts,
        private RouteRegistry $routes,
        private MiddlewareRegistry $middlewares,
        private Repository $config,
        private Container $container,
        private ?UrlBasePath $basePath = null
    ) {
    }

    public function verify(bool $staticOnly = false, ?string $operationId = null,
        bool $strict = false, bool $includeMutations = false): VerificationReport
    {
        $findings = [];
        $summary = [
            'public_operations' => 0, 'operations_with_cases' => 0,
            'operations_without_cases' => 0, 'declared_response_statuses' => 0,
            'statuses_exercised' => 0, 'cases_registered' => 0,
            'cases_executed' => 0, 'cases_passed' => 0,
            'cases_failed' => 0, 'cases_skipped' => 0,
            'warnings' => 0, 'errors' => 0,
        ];
        $failOnWarning = $strict || $this->config->get('contract.verification.fail_on_warning', false) === true;
        try {
            // Both export directions must remain valid, but verification never
            // parses OpenAPI back into the framework's source of truth.
            $artifact = $this->contracts->application();
            $this->contracts->openApi();
            $this->contracts->json('squehub');
        } catch (Throwable) {
            $findings[] = new VerificationFinding('error', 'contract_invalid');
            return $this->report($summary, $findings, $failOnWarning);
        }

        $operations = [];
        foreach ($artifact['operations'] as $operation) {
            if ($operationId !== null && $operation['operation_id'] !== $operationId) continue;
            $operations[$operation['operation_id']] = $operation;
        }
        if ($operationId !== null && $operations === []) {
            $findings[] = new VerificationFinding('error', 'route_missing', $operationId);
        }
        $summary['public_operations'] = count($operations);
        foreach ($operations as $operation) {
            $summary['declared_response_statuses'] += count($operation['responses']);
            $route = $this->findRoute($operation);
            if ($route === null) {
                $findings[] = new VerificationFinding('error', 'route_missing', $operation['operation_id']);
                continue;
            }
            if (!in_array($operation['method'], $route->methods(), true)) {
                $findings[] = new VerificationFinding('error', 'method_mismatch', $operation['operation_id']);
            }
            if ($operation['api_version'] !== $route->apiVersionValue()) {
                $findings[] = new VerificationFinding('error', 'version_mismatch', $operation['operation_id']);
            }
            foreach ($this->securityFindings($operation, $route) as $finding) $findings[] = $finding;
        }

        $cases = [];
        $caseCounts = array_fill_keys(array_keys($operations), 0);
        foreach ($this->contracts->verificationCases() as $case) {
            $id = $case->operationId();
            if ($operationId !== null && $id !== $operationId) continue;
            $cases[] = $case;
            if ($id === null) {
                $findings[] = new VerificationFinding('error', 'case_invalid', caseName: $case->name());
            } elseif (!isset($operations[$id])) {
                $findings[] = new VerificationFinding('error', 'route_missing', $id, $case->name());
            } else {
                ++$caseCounts[$id];
            }
        }
        $summary['cases_registered'] = count($cases);
        foreach ($caseCounts as $id => $count) {
            if ($count > 0) {
                ++$summary['operations_with_cases'];
            } else {
                ++$summary['operations_without_cases'];
                $required = $strict || $this->config->get('contract.verification.require_case_per_operation', false) === true;
                if ($required) {
                    $findings[] = new VerificationFinding('error', 'operation_uncovered', $id);
                }
            }
        }

        // The verifier is not a sandbox. Production and unknown environments
        // may inspect declarations but never execute arbitrary application PHP.
        $canExecute = in_array(strtolower($this->app->environment()),
            ['development', 'testing', 'test', 'local'], true);
        if (!$staticOnly && !$canExecute && $cases !== []) {
            $findings[] = new VerificationFinding('error', 'execution_prohibited');
        }
        $statuses = [];
        foreach ($cases as $case) {
            $id = $case->operationId();
            // Unsafe HTTP methods require the same explicit opt-in as a case
            // marked mutation; a forgotten marker must not execute a POST.
            $method = $case->methodValue() ?? ($operations[$id]['method'] ?? null);
            $isMutation = $case->isMutation() || ($method !== null
                && !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true));
            if ($staticOnly || !$canExecute || ($isMutation && !$includeMutations)
                || $id === null || !isset($operations[$id])) {
                ++$summary['cases_skipped'];
                if (!$staticOnly && $canExecute && $isMutation && !$includeMutations) {
                    $findings[] = new VerificationFinding('info', 'mutation_skipped', $id, $case->name());
                }
                continue;
            }
            ++$summary['cases_executed'];
            [$caseFindings, $status] = $this->runCase($case, $operations[$id], $artifact['schemas']);
            if ($status !== null) $statuses[$id . ':' . $status] = true;
            if ($caseFindings === []) ++$summary['cases_passed'];
            else ++$summary['cases_failed'];
            foreach ($caseFindings as $finding) $findings[] = $finding;
        }
        $summary['statuses_exercised'] = count($statuses);
        return $this->report($summary, $findings, $failOnWarning);
    }

    /** @param array<string,mixed> $operation */
    private function findRoute(array $operation): ?RouteDefinition
    {
        foreach ($this->routes->all() as $route) {
            if ($route->uri() === $operation['path']
                && in_array($operation['method'], $route->methods(), true)
                && $route->contractValue() !== null) return $route;
        }
        return null;
    }

    /** Known built-in middleware can be compared; custom PHP remains opaque. */
    private function securityFindings(array $operation, RouteDefinition $route): array
    {
        $id = $operation['operation_id'];
        $declared = $operation['security'];
        $token = false;
        $session = false;
        $unknown = false;
        $abilities = [];
        $authorizationAbilities = [];
        foreach ($route->middlewares() as $entry) {
            try {
                $class = is_string($entry) ? $this->middlewares->resolve($entry) : $entry::class;
            } catch (Throwable) {
                $unknown = true;
                continue;
            }
            if ($entry instanceof RequireToken) $token = true;
            elseif ($class === RequireToken::class) $unknown = true;
            elseif ($entry instanceof RequireTokenAbility) $abilities[] = $entry->abilityName();
            elseif ($class === RequireTokenAbility::class) $unknown = true;
            elseif ($entry instanceof RequireAbility) $authorizationAbilities[] = $entry->abilityName();
            elseif ($class === RequireAbility::class) $unknown = true;
            elseif ($class === RequireAuthentication::class) $session = true;
            else $unknown = true;
        }
        sort($abilities, SORT_STRING);
        $wanted = $declared['token_abilities'] ?? [];
        sort($wanted, SORT_STRING);
        $declaredAuthorization = $declared['authorization_abilities'] ?? [];
        sort($declaredAuthorization, SORT_STRING);
        $kind = $declared['type'] ?? 'none';
        $mismatch = ($kind === 'pat' && (!$token || $session || $abilities !== $wanted))
            || ($kind === 'session' && (!$session || $token))
            || ($kind === 'none' && ($token || $session || $abilities !== []))
            || array_diff($authorizationAbilities, $declaredAuthorization) !== [];
        // A controller may enforce a documented authorization ability without
        // route middleware. Only the reverse is a provable declaration drift.
        $unobservedAuthorization = array_diff($declaredAuthorization, $authorizationAbilities) !== [];
        if ($mismatch && !$unknown) {
            return [new VerificationFinding('error', 'security_mismatch', $id)];
        }
        if ($mismatch || $unknown || $unobservedAuthorization) {
            return [new VerificationFinding('warning', 'security_unverifiable', $id)];
        }
        return [];
    }

    /** @return array{list<VerificationFinding>,?int} */
    private function runCase(VerificationCase $case, array $operation, array $schemas): array
    {
        $findings = [];
        $status = null;
        $id = $operation['operation_id'];
        $setupSucceeded = true;
        try {
            $case->setupCallback()?->__invoke($this->app);
        } catch (Throwable) {
            $setupSucceeded = false;
            $findings[] = new VerificationFinding('error', 'fixture_failed', $id, $case->name());
        }
        try {
            if ($setupSucceeded) {
                try {
                    $request = $this->request($case, $operation, $schemas);
                    $response = $this->container->make(Kernel::class)->handle($request);
                    $status = $response->status();
                    $findings = $this->responseFindings($case, $operation, $response, $schemas);
                } catch (RequestContractMismatch $exception) {
                    $findings[] = new VerificationFinding('error', 'request_schema_mismatch', $id,
                        $case->name(), $exception->path(), $exception->expected(), $exception->actual());
                } catch (Throwable) {
                    // Neither fixture nor infrastructure throwable text is safe
                    // report material. Kernel-converted exceptions are Responses.
                    $findings[] = new VerificationFinding('error', 'case_failed', $id, $case->name());
                }
            }
        } finally {
            try {
                $case->cleanupCallback()?->__invoke($this->app);
            } catch (Throwable) {
                $findings[] = new VerificationFinding('error', 'fixture_failed', $id, $case->name());
            }
        }
        return [$findings, $status];
    }

    /** @param array<string,mixed> $operation */
    private function request(VerificationCase $case, array $operation, array $schemas): Request
    {
        $path = $case->uriValue() ?? $operation['path'];
        if ($case->uriValue() === null) {
            $used = [];
            $path = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
                static function (array $match) use ($case, &$used): string {
                    $name = $match[1];
                    $params = $case->routeParameters();
                    if (!array_key_exists($name, $params)) {
                        throw new ContractException('Verification route parameter is missing.');
                    }
                    $value = (string) $params[$name];
                    if ($value === '' || in_array($value, ['.', '..'], true)
                        || strpbrk($value, "/\\\r\n") !== false) {
                        throw new ContractException('Verification route parameter is invalid.');
                    }
                    $used[$name] = true;
                    return rawurlencode($value);
                }, $path);
            if (count($used) !== count($case->routeParameters())) {
                throw new ContractException('Verification route parameters do not match the operation.');
            }
        }
        $query = $case->queryValues();
        if ($query !== []) {
            try {
                $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            } catch (Throwable) {
                throw new ContractException('Verification query input is invalid.');
            }
            if (strlen($encoded) > 8192) throw new ContractException('Verification query input is too large.');
            $path .= '?' . $encoded;
        }
        $headers = ['Accept' => 'application/json'];
        $body = '';
        if ($case->hasJsonBody()) {
            try {
                $body = json_encode($case->jsonValue(), JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException $exception) {
                throw new ContractException('Verification JSON input is invalid.', 0, $exception);
            }
            $headers['Content-Type'] = 'application/json';
            $declaration = $operation['request_body'];
            // Invalid-body cases deliberately expect a 4xx response and must
            // reach the application's real validation path.
            if ($declaration !== null && ($case->expectedStatus() === null
                || $case->expectedStatus() < 400)) {
                $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                $failure = $this->container->make(ResponseSchemaValidator::class)->validate(
                    $decoded, $declaration['schema'], $schemas);
                if ($failure !== null) {
                    throw new RequestContractMismatch($failure);
                }
            }
        } elseif ($case->formValues() !== []) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (($operation['request_body']['required'] ?? false)
            && ($case->expectedStatus() === null || $case->expectedStatus() < 400)) {
            throw new ContractException('Verification required request body is missing.');
        }
        foreach ($case->headerValues() as $name => $value) $headers[$name] = $value;
        // Contract operation paths stay portable. Only the synthetic request
        // entering this Application's Kernel needs its deployment mount.
        $path = $this->basePath?->publicPath($path) ?? $path;
        return new Request($case->methodValue() ?? $operation['method'], $path, $query,
            $case->formValues(), $case->cookieValues(), [], $headers, [], $body);
    }

    /** @return list<VerificationFinding> */
    private function responseFindings(VerificationCase $case, array $operation,
        Response $response, array $schemas): array
    {
        $id = $operation['operation_id'];
        $name = $case->name();
        $status = $response->status();
        $findings = [];
        if ($case->expectedStatus() !== null && $case->expectedStatus() !== $status) {
            $findings[] = new VerificationFinding('error', 'status_mismatch', $id, $name,
                expected: (string) $case->expectedStatus(), actual: (string) $status);
        }
        $declaration = $operation['responses'][(string) $status]
            ?? $operation['responses']['default'] ?? null;
        if ($declaration === null) {
            $code = $status >= 300 && $status < 400 ? 'unexpected_redirect' : 'status_undeclared';
            $findings[] = new VerificationFinding('error', $code, $id, $name,
                actual: (string) $status);
            return $findings;
        }
        $expectedType = $declaration['content_type'] ?? null;
        if ($expectedType !== null) {
            $actualType = strtolower(trim(explode(';', $response->header('Content-Type') ?? '', 2)[0]));
            if (strcasecmp($actualType, $expectedType) !== 0) {
                // The response header is application-controlled and may contain
                // a secret-like token. Report its category, never its bytes.
                $findings[] = new VerificationFinding('error', 'content_type_mismatch', $id,
                    $name, expected: $expectedType, actual: $actualType === '' ? 'missing' : 'different');
            }
        }
        foreach ($declaration['headers'] as $headerName => $header) {
            $value = $response->header($headerName);
            if ($value === null) {
                $findings[] = new VerificationFinding('error', 'header_missing', $id, $name,
                    path: '$.headers.' . $headerName);
                continue;
            }
            $typed = $this->headerValue($value, $header['schema']);
            $failure = $this->container->make(ResponseSchemaValidator::class)->validate(
                $typed, $header['schema'], $schemas);
            if ($failure !== null) {
                $findings[] = new VerificationFinding('error', 'header_mismatch', $id, $name,
                    path: '$.headers.' . $headerName,
                    expected: $failure['expected'], actual: $failure['actual']);
            }
        }
        if ($declaration['schema'] !== null && str_ends_with(strtolower((string) $expectedType), 'json')) {
            try {
                // Object identity matters: associative decoding would collapse
                // empty JSON objects into arrays and misapply object schemas.
                $decoded = json_decode($response->content(), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $findings[] = new VerificationFinding('error', 'json_invalid', $id, $name);
                return $findings;
            }
            $failure = $this->container->make(ResponseSchemaValidator::class)->validate(
                $decoded, $declaration['schema'], $schemas);
            if ($failure !== null) {
                $findings[] = new VerificationFinding('error', 'response_schema_mismatch', $id,
                    $name, $failure['path'], $failure['expected'], $failure['actual']);
            }
        }
        return $findings;
    }

    /** HTTP headers are text; numeric declarations describe their parsed value. */
    private function headerValue(string $value, array|bool $schema): mixed
    {
        if (!is_array($schema)) return $value;
        $type = $schema['type'] ?? null;
        if ($type === 'integer' && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            return $parsed === false ? $value : $parsed;
        }
        if ($type === 'number' && is_numeric($value)) return (float) $value;
        if ($type === 'boolean' && in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }
        return $value;
    }

    /** @param array<string,int> $summary @param list<VerificationFinding> $findings */
    private function report(array $summary, array $findings, bool $failOnWarning): VerificationReport
    {
        foreach ($findings as $finding) {
            if ($finding->severity === 'error') ++$summary['errors'];
            elseif ($finding->severity === 'warning') ++$summary['warnings'];
        }
        return new VerificationReport($summary, $findings, $failOnWarning);
    }
}
