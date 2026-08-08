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

    /** Delegator factories: id => list<callable|class-string> */
    public final const string DELEGATORS = 'delegators';

    /** Pre-instantiated services: id => instance */
    public final const string SERVICES = 'services';

    /** App config */
    public final const string CONFIG = 'config';

    /** Custom parameter resolvers: priority => class-string|callable|ParameterResolverInterface */
    public final const string PARAMETER_RESOLVERS = 'parameter_resolvers';

    /** When true, the default parameter resolver chain is NOT installed; only the user-supplied resolvers are. */
    public final const string PARAMETER_RESOLVERS_REPLACE = 'parameter_resolvers_replace';

    /** Runtime attribute handlers in registration order. */
    public final const string ATTRIBUTE_HANDLERS = 'attribute_handlers';

    /** When true, only explicitly configured attribute handlers are installed. */
    public final const string ATTRIBUTE_HANDLERS_REPLACE = 'attribute_handlers_replace';

    /** Generated EntryResolver PHP file loaded before the reflection fallback. */
    public final const string GENERATED_ENTRY_RESOLVER_FILE = 'generated_entry_resolver_file';

    /** Stable release identifier used instead of hashing generated source files. */
    public final const string GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT
        = 'generated_entry_resolver_release_fingerprint';

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
            self::DELEGATORS,
            self::SERVICES,
            self::PARAMETER_RESOLVERS,
            self::PARAMETER_RESOLVERS_REPLACE,
            self::ATTRIBUTE_HANDLERS,
            self::ATTRIBUTE_HANDLERS_REPLACE,
            self::GENERATED_ENTRY_RESOLVER_FILE,
            self::GENERATED_ENTRY_RESOLVER_RELEASE_FINGERPRINT,
        ];
    }
}