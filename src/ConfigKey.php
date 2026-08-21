<?php

declare(strict_types=1);

namespace Componenta\Config;

/**
 * Dependency configuration keys shared by Componenta configuration providers.
 *
 * Package-specific ConfigKey classes may extend this class and add their own
 * configuration keys while inheriting the shared DI schema.
 * Consumer packages remain responsible for validating the values stored under
 * these keys. This package only provides stable names and merge semantics.
 */
class ConfigKey
{
    public const string DEPENDENCIES = 'dependencies';
    public const string FACTORIES = 'factories';
    public const string INVOKABLES = 'invokables';
    public const string ALIASES = 'aliases';
    public const string DELEGATORS = 'delegators';
    public const string SERVICES = 'services';
    public const string PARAMETER_RESOLVERS = 'parameter_resolvers';
    public const string PARAMETER_RESOLVERS_REPLACE = 'parameter_resolvers_replace';
    public const string ATTRIBUTE_DEFINITIONS = 'attribute_definitions';
    public const string ATTRIBUTE_DEFINITIONS_REPLACE = 'attribute_definitions_replace';
    public const string ATTRIBUTE_CAPABILITIES = 'attribute_capabilities';

    /** @return list<string> */
    public static function dependencyKeys(): array
    {
        return [
            self::FACTORIES,
            self::INVOKABLES,
            self::ALIASES,
            self::DELEGATORS,
            self::SERVICES,
            self::PARAMETER_RESOLVERS,
            self::PARAMETER_RESOLVERS_REPLACE,
            self::ATTRIBUTE_DEFINITIONS,
            self::ATTRIBUTE_DEFINITIONS_REPLACE,
            self::ATTRIBUTE_CAPABILITIES,
        ];
    }

    protected function __construct() {}
}
