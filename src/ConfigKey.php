<?php

declare(strict_types=1);

namespace Componenta\Config;

/**
 * Configuration keys for DI container.
 *
 * @example Usage in config files
 * ```php
 * use Componenta\DI\ConfigKey;
 *
 * return [
 *     ConfigKey::DEPENDENCIES => [
 *         ConfigKey::FACTORIES => [...],
 *         ConfigKey::ALIASES => [...],
 *     ],
 * ];
 * ```
 */
class ConfigKey
{

    /** Root key for all DI configuration */
    public final const string DEPENDENCIES = 'dependencies';

    // ==========================================================================
    // Dependencies section keys
    // ==========================================================================

    /** Factory callables: id => callable|class-string */
    public final const string FACTORIES = 'factories';

    /** Simple classes without dependencies: list<class-string> or id => class-string */
    public final const string INVOKABLES = 'invokables';

    /** Service aliases: alias => target */
    public final const string ALIASES = 'aliases';

    /** Classes for autowiring: list<class-string> */
    public final const string AUTOWIRES = 'autowires';

    /** Delegator factories: id => list<callable|class-string> */
    public final const string DELEGATORS = 'delegators';

    /** Pre-instantiated services: id => instance */
    public final const string SERVICES = 'services';

    /** App config */
    public final const string CONFIG = 'config';

    /** Custom parameter resolvers: priority => class-string|callable|ParameterResolverInterface */
    public final const string PARAMETER_RESOLVERS = 'parameter_resolvers';

    /** Custom property resolvers: priority => class-string|callable|PropertyResolverInterface */
    public final const string PROPERTY_RESOLVERS = 'property_resolvers';

    /** When true, the default parameter resolver chain is NOT installed; only the user-supplied resolvers are. */
    public final const string PARAMETER_RESOLVERS_REPLACE = 'parameter_resolvers_replace';

    /** When true, the default property resolver chain is NOT installed; only the user-supplied resolvers are. */
    public final const string PROPERTY_RESOLVERS_REPLACE = 'property_resolvers_replace';

    /**
     * Merge marker: when present in an array, numeric keys
     * are replaced by index instead of appended.
     *
     * @see config_merge()
     */
    public final const string OVERRIDE_INDEXES = '__override_indexes__';

    /**
     * Get all dependency section keys.
     *
     * @return list<string>
     */
    public final static function dependencyKeys(): array
    {
        return [
            self::FACTORIES,
            self::INVOKABLES,
            self::ALIASES,
            self::AUTOWIRES,
            self::DELEGATORS,
            self::SERVICES,
            self::CONFIG,
        ];
    }
}