<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Authorization\Rbac\RbacManager;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

/**
 * Owns explicit global abilities and exact resource-policy mappings.
 * Auth supplies the current identity; decisions and resolved rules are never cached.
 */
final class AuthorizationManager
{
    /** @var array<string, callable|class-string> */
    private array $abilities = [];
    /** @var array<class-string, class-string> */
    private array $policies = [];

    public function __construct(
        private AuthManager $auth,
        private Container $container,
        private ?Diagnostics $diagnostics = null,
        private ?RbacManager $rbac = null
    ) {
    }

    /** Register one global ability; class rules are inspected but resolved lazily. */
    public function define(string $ability, mixed $rule): void
    {
        $this->validGlobalName($ability);
        if (isset($this->abilities[$ability])) {
            throw new AuthorizationConfigurationException('Global ability is already registered.');
        }
        if (is_string($rule)) {
            $this->ruleMethod($rule);
        } elseif (!is_callable($rule)) {
            throw new AuthorizationConfigurationException('Global ability rule must be callable or a class name.');
        }
        $this->abilities[$ability] = $rule;
    }

    /** Register an exact subject mapping; a policy instance is made only for a check. */
    public function policy(string $subjectClass, string $policyClass): void
    {
        if (!class_exists($subjectClass)) {
            throw new AuthorizationConfigurationException('Policy subject must be an existing class.');
        }
        // PHP class spelling is case-insensitive after autoloading; use its
        // declared name so a case variant cannot create a second mapping.
        $subjectClass = (new ReflectionClass($subjectClass))->getName();
        if (isset($this->policies[$subjectClass])) {
            throw new AuthorizationConfigurationException('Policy subject is already registered.');
        }
        $this->instantiable($policyClass, 'Policy');
        $this->policies[$subjectClass] = $policyClass;
    }

    public function forIdentity(?object $identity): AuthorizationContext
    {
        return new AuthorizationContext($this, $identity);
    }

    public function allows(string $ability, object|string|null $subject = null): bool
    {
        return $this->decideCurrent($ability, $subject)->allowed();
    }

    public function denies(string $ability, object|string|null $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    public function require(string $ability, object|string|null $subject = null): void
    {
        $decision = $this->decideCurrent($ability, $subject);
        if ($decision->denied()) throw new AuthorizationException($decision->message() ?? 'This action is not authorized.');
    }

    /** @internal Explicit contexts bypass Auth identity lookup without changing Auth. */
    public function decideFor(?object $identity, string $ability, object|string|null $subject = null): AuthorizationDecision
    {
        return $this->evaluate(static fn (): ?object => $identity, $ability, $subject);
    }

    private function decideCurrent(string $ability, object|string|null $subject): AuthorizationDecision
    {
        return $this->evaluate(
            fn (): ?object => $this->auth->hasDefaultGuard() ? $this->auth->user() : null,
            $ability,
            $subject
        );
    }

    /** Count one outcome and retain no identity, ability, subject, or message. */
    private function evaluate(Closure $identity, string $ability, object|string|null $subject): AuthorizationDecision
    {
        $this->diagnostics?->authorization('checks');
        try {
            // Missing registration is broken application wiring even for a
            // guest. The check never executes a policy or rule for that guest.
            if ($subject === null) {
                $this->validGlobalName($ability);
                if (!isset($this->abilities[$ability]) && !($this->rbac?->permissionExists($ability) ?? false)) {
                    throw new AuthorizationConfigurationException('Global ability is not registered.');
                }
            } else {
                $this->validPolicyName($ability);
            }
            if (is_string($subject) && !class_exists($subject)) {
                throw new AuthorizationConfigurationException('Policy subject must be an existing class.');
            }
            if ($subject !== null) {
                $subjectClass = is_object($subject) ? $subject::class : $subject;
                $subjectClass = (new ReflectionClass($subjectClass))->getName();
                if (!isset($this->policies[$subjectClass])) {
                    throw new AuthorizationConfigurationException('Policy is not registered for this subject class.');
                }
            }
            $user = $identity();
            // A guest never invokes application policy code, including an
            // otherwise configured rule. Public routes need no authorization.
            $decision = $user === null ? AuthorizationDecision::deny()
                : ($subject === null
                    ? $this->globalDecision($ability, $user)
                    : $this->policyDecision($ability, $user, $subject));
            $this->diagnostics?->authorization($decision->allowed() ? 'allowed' : 'denied');
            return $decision;
        } catch (Throwable $failure) {
            $this->diagnostics?->authorization('errors');
            throw $failure;
        }
    }

    private function globalDecision(string $ability, object $identity): AuthorizationDecision
    {
        // An explicit rule, including a denial, always wins over a role grant.
        // Registered permissions fill only otherwise unbound global abilities.
        if (!isset($this->abilities[$ability])) {
            return $identity instanceof Authenticatable
                && $this->rbac?->hasPermission($identity, $ability)
                    ? AuthorizationDecision::allow() : AuthorizationDecision::deny();
        }
        $rule = $this->abilities[$ability];
        if (is_string($rule)) {
            // Container lifetime controls rule lifetime; Authorization does not
            // retain the object or reuse an earlier decision.
            $method = $this->ruleMethod($rule);
            $this->validParameters($method->getParameters(), [$identity], $method->getDeclaringClass()->getName());
            $result = $this->container->make($rule)->check($identity);
        } else {
            $reflection = new ReflectionFunction(Closure::fromCallable($rule));
            $this->validParameters($reflection->getParameters(), [$identity]);
            $result = $rule($identity);
        }
        return $this->decision($result);
    }

    private function policyDecision(string $ability, object $identity, object|string $subject): AuthorizationDecision
    {
        $subjectClass = is_object($subject) ? $subject::class : $subject;
        $subjectClass = (new ReflectionClass($subjectClass))->getName();
        $policyClass = $this->policies[$subjectClass];
        if (!method_exists($policyClass, $ability)) {
            throw new AuthorizationConfigurationException('Policy ability method is not defined.');
        }
        $method = new ReflectionMethod($policyClass, $ability);
        if (!$method->isPublic() || $method->isStatic() || str_starts_with($method->getName(), '__')) {
            throw new AuthorizationConfigurationException('Policy ability method must be public, non-static, and non-magic.');
        }
        $arguments = is_object($subject) ? [$identity, $subject] : [$identity];
        $this->validParameters($method->getParameters(), $arguments, $method->getDeclaringClass()->getName());
        return $this->decision($method->invokeArgs($this->container->make($policyClass), $arguments));
    }

    private function decision(mixed $result): AuthorizationDecision
    {
        if ($result instanceof AuthorizationDecision) return $result;
        if (is_bool($result)) return $result ? AuthorizationDecision::allow() : AuthorizationDecision::deny();
        throw new AuthorizationConfigurationException('Authorization rule must return bool or AuthorizationDecision.');
    }

    private function ruleMethod(string $class): ReflectionMethod
    {
        $this->instantiable($class, 'Global ability rule');
        if (!method_exists($class, 'check')) {
            throw new AuthorizationConfigurationException('Global ability class needs a public check() method.');
        }
        $method = new ReflectionMethod($class, 'check');
        if (!$method->isPublic() || $method->isStatic()) {
            throw new AuthorizationConfigurationException('Global ability check() must be public and non-static.');
        }
        $this->validParameterCount($method->getParameters(), 1);
        return $method;
    }

    private function instantiable(string $class, string $role): void
    {
        if (!class_exists($class) || !(new ReflectionClass($class))->isInstantiable()) {
            throw new AuthorizationConfigurationException($role . ' must be an existing instantiable class.');
        }
    }

    /** Validate method arity and obvious type mismatches before application code runs. */
    private function validParameters(array $parameters, array $arguments, ?string $declaringClass = null): void
    {
        $this->validParameterCount($parameters, count($arguments));
        foreach ($parameters as $index => $parameter) {
            if (!$this->accepts($parameter->getType(), $arguments[$index], $declaringClass)) {
                throw new AuthorizationConfigurationException('Authorization rule parameter type does not accept its argument.');
            }
        }
    }

    /** @param list<ReflectionParameter> $parameters */
    private function validParameterCount(array $parameters, int $expected): void
    {
        if (count($parameters) !== $expected) {
            throw new AuthorizationConfigurationException('Authorization rule has an invalid parameter count.');
        }
        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new AuthorizationConfigurationException('Authorization rule parameters cannot be variadic or by reference.');
            }
        }
    }

    private function accepts(?ReflectionType $type, object $value, ?string $declaringClass): bool
    {
        if ($type === null) return true;
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if ($this->accepts($part, $value, $declaringClass)) return true;
            }
            return false;
        }
        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if (!$this->accepts($part, $value, $declaringClass)) return false;
            }
            return true;
        }
        if (!$type instanceof ReflectionNamedType) return false;
        $name = $type->getName();
        if ($name === 'mixed' || $name === 'object') return true;
        if ($name === 'self' || $name === 'static') $name = $declaringClass ?? '';
        if ($name === 'parent') $name = $declaringClass === null ? '' : (get_parent_class($declaringClass) ?: '');
        return $name !== '' && is_a($value, $name);
    }

    private function validGlobalName(string $ability): void
    {
        if (strlen($ability) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9._-]*\z/D', $ability) !== 1) {
            throw new AuthorizationConfigurationException('Global ability name must be a bounded identifier.');
        }
    }

    private function validPolicyName(string $ability): void
    {
        if (strlen($ability) > 128 || str_starts_with($ability, '__')
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $ability) !== 1) {
            throw new AuthorizationConfigurationException('Policy ability must be a non-magic method name.');
        }
    }
}
