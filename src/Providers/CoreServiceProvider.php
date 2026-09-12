<?php

declare(strict_types=1);

namespace Thallo\Core\Providers;

use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Updates\Console\UpdateCheckCommand;
use Thallo\Core\Updates\PackagistReleaseFeed;
use Thallo\Core\Updates\ReleaseFeed;
use Thallo\Core\Updates\UpdateChecker;
use Thallo\Core\Capabilities\DefaultCapabilityRegistry;
use Thallo\Core\Capabilities\ExtensionCapabilityAvailabilityResolver;
use Thallo\Core\Setup\InstallRoleGrants;
use Thallo\Core\Setup\SetupService;
use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Delivery\EngineMediaUrlResolver;
use Thallo\Core\Content\Delivery\EngineMediaVariantUrlResolver;
use Thallo\Core\Content\Delivery\FilterCompiler;
use Thallo\Core\Content\Delivery\ReferenceFilterResolver;
use Thallo\Core\Content\Delivery\ReferenceResolver;
use Thallo\Core\Content\Delivery\SortCompiler;
use Thallo\Core\Content\Delivery\ThalloCanonicalPublicOriginResolver;
use Thallo\Core\Content\Forms\DefaultFormSealer;
use Thallo\Core\Content\Forms\FormFieldDerivation;
use Thallo\Core\Content\Forms\FormMailSender;
use Thallo\Core\Content\Forms\FormNotifier;
use Thallo\Core\Content\Forms\FormSubmissionRepository;
use Thallo\Core\Content\Forms\Spam\DefaultFormGuard;
use Thallo\Core\Content\Forms\Spam\FormSubmissionGuard;
use Thallo\Core\Content\Media\TenantBlobPolicy;
use Thallo\Core\Content\Media\TenantBlobPublicUrlProvider;
use Thallo\Core\Content\Media\TenantBlobRouteMiddlewareProvider;
use Thallo\Core\Content\Authorization\OperatorBypass;
use Thallo\Core\Content\Authorization\AuthenticatedPrincipalResolver;
use Thallo\Core\Content\Authorization\PermissionAuthority;
use Thallo\Core\Content\Authorization\CapabilityCatalog;
use Thallo\Core\Content\Authorization\BuiltinRoleAvailabilityRepository;
use Thallo\Core\Content\Authorization\EffectiveRoleEvaluator;
use Thallo\Core\Content\Authorization\EffectiveRoleMatrix;
use Thallo\Core\Content\Authorization\PermissionImplicationSource;
use Thallo\Core\Content\Authorization\PermissionRequirementAuthority;
use Thallo\Core\Content\Authorization\PolicyManifest;
use Thallo\Core\Content\Authorization\RolePolicyDiagnostics;
use Thallo\Core\Content\Authorization\RoleMatrix;
use Thallo\Core\Content\Authorization\TenantMembershipRoleReader;
use Thallo\Core\Content\Authorization\TenantRoleOverrideRepository;
use Thallo\Core\Content\Authorization\TenantRolePolicyMutator;
use Thallo\Core\Content\Authorization\TenantRoleRepository;
use Thallo\Core\Content\Authorization\TenantRoleLifecycle;
use Thallo\Core\Content\Authorization\ThalloMembershipRoleAuthority;
use Thallo\Core\Http\Middleware\AdminTenantBindingMiddleware;
use Glueful\Encryption\EncryptionService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantRuntimeReadiness;
use Glueful\Uploader\Contracts\BlobAccessPolicy;
use Glueful\Uploader\Contracts\BlobCreatedHook;
use Glueful\Uploader\Contracts\BlobPublicUrlProvider;
use Glueful\Uploader\Contracts\BlobRouteMiddlewareProvider;
use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Events\DomainReverificationFailed;
use Glueful\Extensions\Tenancy\Events\DomainReverified;
use Glueful\Extensions\Tenancy\Events\DomainRevoked;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;
use Thallo\Contracts\Content\FormSealer;
use Thallo\Tenancy\Reverification\DomainReverificationAuditListener;
use Thallo\Core\Content\Console\PruneVersionsCommand;
use Thallo\Core\Content\Console\PolicyManifestCommand;
use Thallo\Core\Content\Console\RetireAccountLinkCommand;
use Thallo\Core\Content\Console\RunBlockBackfillCommand;
use Thallo\Core\Content\Console\SeedBlockTypesCommand;
use Thallo\Core\Content\Console\SyncBlockTypesCommand;
use Thallo\Core\Content\Console\ResyncCommand;
use Thallo\Core\Content\Console\RunBackfillCommand;
use Thallo\Core\Content\Console\RunDueSchedulesCommand;
use Thallo\Core\Setup\Console\CreateAdminCommand;
use Thallo\Core\Setup\Console\DoctorCommand;
use Thallo\Core\Setup\Console\ProvisionCommand;
use Thallo\Core\Setup\Console\SuperuserGrantCommand;
use Thallo\Core\Setup\Console\SuperuserTransferCommand;
use Thallo\Core\Settings\Console\MigratePlatformPaymentCredentialsCommand;
use Thallo\Core\Content\Backfill\BackfillRunner;
use Thallo\Core\Content\Indexing\FilterIndexJobDispatcher;
use Thallo\Core\Http\Controllers\AdminConfigController;
use Thallo\Core\Http\Controllers\AssignableRolesController;
use Thallo\Core\Http\Controllers\ApiKeyAdminController;
use Thallo\Core\Http\Controllers\CacheAdminController;
use Thallo\Core\Http\Controllers\CapabilityAdminController;
use Thallo\Core\Http\Controllers\ExtensionAdminController;
use Thallo\Core\Http\Controllers\FormSubmissionsController;
use Thallo\Core\Http\Controllers\FormSubmitController;
use Thallo\Core\Http\Controllers\GeneralSettingsController;
use Thallo\Core\Http\Controllers\HealthAdminController;
use Thallo\Core\Http\Controllers\UpdateStatusController;
use Thallo\Core\Http\Controllers\IconInventoryController;
use Thallo\Core\Http\Controllers\ImportExportController;
use Thallo\Core\Http\Controllers\MediaAdminController;
use Thallo\Core\Http\Controllers\PlatformPaymentsSettingsController;
use Thallo\Core\Http\Controllers\RegionAdminController;
use Thallo\Core\Http\Controllers\ScheduledTasksController;
use Thallo\Core\Http\Controllers\TenancyAccessController;
use Thallo\Core\Http\Controllers\UserAdminController;
use Thallo\Core\Http\Controllers\TenantHostCooldownController;
use Thallo\Core\Http\Controllers\TenantRolesController;
use Thallo\Core\Http\Controllers\SignupController;
use Thallo\Core\Signup\ContinuationTokens;
use Thallo\Core\Signup\CustomerSignupService;
use Thallo\Core\Signup\DefaultSignupDiagnostics;
use Thallo\Core\Signup\MemberSignupService;
use Thallo\Core\Signup\NullSignupChallenge;
use Thallo\Core\Signup\RejectingSignupChallenge;
use Thallo\Core\Signup\SignupChallenge;
use Thallo\Core\Signup\SignupConfig;
use Thallo\Core\Signup\SignupCoordinator;
use Thallo\Core\Signup\SignupIntentRepository;
use Thallo\Core\Signup\SignupMailSender;
use Thallo\Core\Signup\VerifiedAccountActivator;
use Thallo\Core\Signup\SignupRolePolicy;
use Thallo\Core\Signup\SignupTelemetry;
use Thallo\Core\Signup\SignupThrottle;
use Thallo\Core\Signup\SignupVerifier;
use Thallo\Core\Signup\WorkspaceSignupService;
use Thallo\Contracts\Tenancy\SignupDiagnostics;
use Thallo\Core\Support\AuthorityAudit;
use Thallo\Core\Support\AuthorityContinuityGuard;
use Thallo\Core\Support\AuthorityMutator;
use Thallo\Core\Support\RoleAuthority;
use Thallo\Core\Support\UserRoleAssignmentPolicy;
use Thallo\Core\Support\TenancyLifecycleAudit;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit as TenancyLifecycleAuditContract;
use Thallo\Contracts\Tenancy\RolePolicyDiagnostics as RolePolicyDiagnosticsContract;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Thallo\Core\Settings\SystemKeyReconciler;
use Thallo\Contracts\Settings\SystemKeyReconciler as SystemKeyReconcilerContract;
use Thallo\Core\Content\Http\Controllers\BlockMigrationController;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Content\Http\Controllers\ContentTypeController;
use Thallo\Core\Http\Controllers\SetupController;
use Thallo\Core\Content\Http\Controllers\DeliveryController;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\Controllers\LocaleAdminController;
use Thallo\Core\Content\Http\Controllers\MigrationController;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\Controllers\PublicationController;
use Thallo\Core\Content\Http\Controllers\RedirectController;
use Thallo\Core\Content\Http\Controllers\ScheduleController;
use Thallo\Core\Content\Http\Controllers\TaxonomyController;
use Thallo\Core\Content\ImportExport\ContentExporter;
use Thallo\Core\Content\ImportExport\ContentImporter;
use Thallo\Core\Content\Http\DeliveryEtag;
use Thallo\Core\Content\Events\EntryCreated;
use Thallo\Core\Content\Events\EntryDeleted;
use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Content\Events\EntryUnpublished;
use Thallo\Core\Content\Events\EntryUpdated;
use Thallo\Core\Content\Events\ModelCreated;
use Thallo\Core\Content\Events\ModelDeleted;
use Thallo\Core\Content\Events\ModelUpdated;
use Thallo\Core\Content\Http\DeliveryAccessMiddleware;
use Thallo\Core\Content\Http\OptionalApiKeyAuthMiddleware;
use Thallo\Core\Content\Http\RequirePermission;
use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Events\AssetAttached;
use Thallo\Core\Content\Events\AssetDetached;
use Thallo\Core\Analytics\AnalyticsBridgeListener;
use Thallo\Core\Collections\Audit\CollectionAuditListener;
use Thallo\Core\Content\Pipeline\Listeners\DispatchWebhookListener;
use Thallo\Core\Content\Pipeline\Listeners\InvalidateCacheTagsListener;
use Thallo\Core\Content\Pipeline\Listeners\ProjectPublishedReferencesListener;
use Thallo\Core\Content\Pipeline\Listeners\PurgeCdnListener;
use Thallo\Core\Content\Pipeline\Listeners\MediaUsageProjector;
use Thallo\Core\Content\Pipeline\Listeners\ReindexSearchListener;
use Thallo\Core\Content\Pipeline\Listeners\SeoMetaChangedListener;
use Thallo\Contracts\Seo\SeoMetaChanged;
use Thallo\Core\Content\Blocks\BlockMigrationGate;
use Thallo\Core\Content\Blocks\BlockRestoreProjector;
use Thallo\Core\Content\Blocks\EngineBlockEditableFieldResolver;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\BlockUsageScanner;
use Thallo\Core\Content\Blocks\Migration\BlockBackfillRunner;
use Thallo\Core\Content\Regions\EngineRegionReader;
use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Regions\RegionValidator;
use Thallo\Core\Content\Blocks\Migration\BlockInstanceWalker;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationService;
use Thallo\Core\Content\Pipeline\PublishEventEmitter;
use Thallo\Core\Content\Preview\EnginePreviewSessionVerifier;
use Thallo\Core\Content\Preview\PreviewMinter;
use Thallo\Core\Content\Preview\PreviewReader;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\MigrationRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Repositories\ScheduleRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Retention\VersionPruner;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Scheduling\ScheduleRunner;
use Thallo\Core\Content\Scheduling\SchedulerHeartbeat;
use Thallo\Core\Content\Seo\CanonicalProjector;
use Thallo\Core\Content\Seo\EngineSeoHeadProvider;
use Thallo\Core\Content\Routing\RootMountGuard;
use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Thallo\Core\Settings\EngineAdminUrlProvider;
use Thallo\Core\Settings\EngineSiteFaviconProvider;
use Thallo\Core\Settings\EngineThemeAppearanceProvider;
use Thallo\Core\Settings\EngineThemeSettingProvider;
use Thallo\Core\Settings\EngineSiteLogoProvider;
use Thallo\Core\Content\Seo\PathRenderer;
use Thallo\Core\Content\Seo\RedirectRepository;
use Thallo\Core\Content\Seo\RouteResolver;
use Thallo\Core\Content\Services\MigrationService;
use Thallo\Core\Content\Authoring\EngineContentWriter;
use Thallo\Core\Content\Authoring\EngineDraftSummaryReader;
use Thallo\Core\Content\Authoring\EngineEntryExistenceReader;
use Thallo\Core\Content\Delivery\EngineEntryTargetResolver;
use Thallo\Core\Content\Context\EngineContext;
use Thallo\Core\Content\Delivery\EngineContentDeliveryReader;
use Thallo\Core\Content\Delivery\EngineEntryListReader;
use Thallo\Core\Content\Delivery\EngineFacetCountsReader;
use Thallo\Core\Content\Delivery\EngineIndexableContentReader;
use Thallo\Core\Content\Delivery\EnginePublishedEntryBlocksReader;
use Thallo\Core\Content\Schema\FieldTypes\DefaultFieldTypeRegistry;
use Thallo\Core\Content\Schema\FieldTypes\EditorialFieldTypes;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Sanitization\TipTapHtmlSanitizer;
use Thallo\Core\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Bootstrap\RequestLifecycle;
use Glueful\Cache\CacheStore;
use Thallo\Contracts\Authoring\ContentWriter;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority as PermissionRequirementAuthorityContract;
use Thallo\Contracts\Content\BlockEditableFieldResolver;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Contracts\Content\RegionReader;
use Thallo\Contracts\Content\RichHtmlSanitizer;
use Thallo\Contracts\Authoring\DraftSummaryReader;
use Thallo\Contracts\Authoring\PublishGate;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\EntryTargetResolver;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Seo\Meta\SeoMetaResolver;
use Thallo\Contracts\Settings\AdminUrlProvider;
use Thallo\Contracts\Settings\SiteFaviconProvider;
use Thallo\Contracts\Settings\SiteLogoProvider;
use Thallo\Contracts\Settings\ThemeAppearanceProvider;
use Thallo\Contracts\Settings\ThemeSettingProvider;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Context\Context;
use Thallo\Contracts\Delivery\ContentDeliveryReader;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\FacetCountsReader;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Contracts\Delivery\PublishedEntryBlocksReader;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Schema\FieldTypeRegistry;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Permissions\PermissionManager;
use Thallo\Collections\Events\CollectionCreated;
use Thallo\Collections\Events\CollectionDropped;
use Thallo\Collections\Events\CollectionRowCreated;
use Thallo\Collections\Events\CollectionRowDeleted;
use Thallo\Collections\Events\CollectionRowUpdated;
use Thallo\Collections\Events\CollectionUpdated;
use Glueful\Extensions\ServiceProvider;
use Glueful\Permissions\Catalog\Permission;
use Glueful\Support\FieldSelection\Projector;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Wires the Thallo content engine into the application container.
 *
 * Registered in config/serviceproviders.php. The framework's ProviderClassResolver
 * folds app providers into the same provider list as composer extensions, so this
 * provider's services() are collected by the ContainerFactory and its register()/boot()
 * lifecycle is run by the ExtensionManager (it extends ServiceProvider, the gate
 * ExtensionManager::addProvider() requires).
 *
 * services() composes per-domain binding groups (repositories, the content engine +
 * contract implementations, SEO/routing, the delivery read path, pipeline listeners,
 * preview, import/export, maintenance, the HTTP controllers, and console commands). Each
 * group is a small private static method returning a partial binding array; services()
 * array_merges them so the registration reads as a table of contents. All bindings
 * autowire unless they need a factory (config-derived construction) or explicit arguments.
 *
 * Routes: core/routes/*.php are loaded in boot() via loadRoutesFrom(); the root routes/
 * directory is the operator's (RouteManifest still auto-discovers it, so nothing of
 * Thallo's may sit there — the Router throws on a duplicate static route).
 *
 * Config: core/config/*.php are merged as defaults in register(); the root config/ is the
 * operator's overrides (environment overlays under config/{env}/ still win key by key).
 */
final class CoreServiceProvider extends ServiceProvider
{
    /**
     * Guards registerEventListeners() against a double-run. EventService::addListener
     * APPENDS with no dedup, so a second registration would make every listener fire
     * twice — harmless for idempotent cache invalidation, but a real bug for webhooks
     * (duplicate deliveries). ExtensionManager::boot() already guards each provider's
     * boot() to once per app lifecycle; this flag is cheap defence-in-depth on top.
     */
    private bool $listenersRegistered = false;

    /** @return array<string, array<string, mixed>> */
    /** Absolute path under core/ (the product's own tree; the repo root is the operator's). */
    public static function corePath(string $relative = ''): string
    {
        $core = dirname(__DIR__, 2);
        return $relative === '' ? $core : $core . '/' . ltrim($relative, '/');
    }

    public static function services(): array
    {
        return array_merge(
            self::repositoryServices(),
            self::contentEngineServices(),
            self::starterServices(),
            self::seoServices(),
            self::deliveryServices(),
            self::pipelineListenerServices(),
            self::previewServices(),
            self::importExportServices(),
            self::maintenanceServices(),
            self::contentControllerServices(),
            self::platformControllerServices(),
            self::consoleCommandServices(),
            self::formServices(),
            self::signupServices(),
            self::accountServices(),
        );
    }

    /**
     * Storefront account contracts, implemented by the app over the signup pipeline and the users
     * extension. The account PACK consumes only these interfaces, never `Thallo\Core\Signup`.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function accountServices(): array
    {
        $bind = static fn (string $impl): array => [
            'class' => $impl,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            \Thallo\Contracts\Account\StorefrontAccountRegistration::class =>
                $bind(\Thallo\Core\Account\AppStorefrontAccountRegistration::class),
            \Thallo\Contracts\Account\StorefrontAccountRecovery::class =>
                $bind(\Thallo\Core\Account\AppStorefrontAccountRecovery::class),
            \Thallo\Contracts\Account\AccountNavigationRegistry::class =>
                $bind(\Thallo\Core\Account\InMemoryAccountNavigationRegistry::class),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function signupServices(): array
    {
        $autowired = static fn (string $class): array => [
            'class' => $class,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            SignupConfig::class => $autowired(SignupConfig::class),
            SignupRolePolicy::class => $autowired(SignupRolePolicy::class),
            SignupIntentRepository::class => $autowired(SignupIntentRepository::class),
            SignupMailSender::class => $autowired(SignupMailSender::class),
            SignupTelemetry::class => $autowired(SignupTelemetry::class),
            SignupVerifier::class => $autowired(SignupVerifier::class),
            ContinuationTokens::class => $autowired(ContinuationTokens::class),
            SignupThrottle::class => $autowired(SignupThrottle::class),
            VerifiedAccountActivator::class => $autowired(VerifiedAccountActivator::class),
            MemberSignupService::class => $autowired(MemberSignupService::class),
            CustomerSignupService::class => $autowired(CustomerSignupService::class),
            WorkspaceSignupService::class => $autowired(WorkspaceSignupService::class),
            SignupCoordinator::class => $autowired(SignupCoordinator::class),
            SignupController::class => $autowired(SignupController::class),
            DefaultSignupDiagnostics::class => $autowired(DefaultSignupDiagnostics::class),
            SignupDiagnostics::class => [
                'factory' => [self::class, 'makeSignupDiagnostics'],
                'shared' => true,
            ],
            SignupChallenge::class => [
                'factory' => [self::class, 'makeSignupChallenge'],
                'shared' => true,
            ],
        ];
    }

    public static function makeUpdateChecker(ContainerInterface $container): UpdateChecker
    {
        return UpdateChecker::fromContext(
            $container->get(ApplicationContext::class),
            $container->get(ReleaseFeed::class),
            $container->get(SystemChannel::class),
        );
    }

    public static function makeSignupChallenge(ContainerInterface $container): SignupChallenge
    {
        $context = $container->get(ApplicationContext::class);
        $provider = trim((string) config($context, 'signup.challenge.provider', ''));
        if ($provider === '') {
            return new NullSignupChallenge();
        }
        try {
            $challenge = $container->has($provider) ? $container->get($provider) : null;
            return $challenge instanceof SignupChallenge ? $challenge : new RejectingSignupChallenge();
        } catch (\Throwable) {
            return new RejectingSignupChallenge();
        }
    }

    /**
     * Form block backend (form-block spec): the sealed-descriptor sealer. Config-derived
     * (encryption key, descriptor lifetime vs render cache TTL, default recipient, time-trap
     * floor), so it needs a factory rather than autowire.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function formServices(): array
    {
        return [
            FormSealer::class => [
                'factory' => [self::class, 'makeFormSealer'],
                'shared' => true,
            ],
            FormSubmissionRepository::class => [
                'class' => FormSubmissionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            DefaultFormGuard::class => [
                'factory' => [self::class, 'makeFormGuard'],
                'shared' => true,
            ],
            FormSubmissionGuard::class => [
                'factory' => [self::class, 'makeFormGuard'],
                'shared' => true,
            ],
            FormNotifier::class => [
                'factory' => [self::class, 'makeFormNotifier'],
                'shared' => true,
            ],
            FormSubmitController::class => [
                'class' => FormSubmitController::class,
                'shared' => true,
                'autowire' => true,
            ],
            FormSubmissionsController::class => [
                'class' => FormSubmissionsController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeFormNotifier(ContainerInterface $container): FormNotifier
    {
        // FormMailSender is a soft seam: unbound → the notifier no-ops (spec §10).
        $sender = $container->has(FormMailSender::class) ? $container->get(FormMailSender::class) : null;
        return new FormNotifier(
            $sender instanceof FormMailSender ? $sender : null,
            $container->get(LoggerInterface::class),
        );
    }

    /** Static (compilable) factories: production compiles the container and refuses closures. */
    public static function makeMigratePlatformPaymentCredentialsCommand(
        ContainerInterface $container,
    ): MigratePlatformPaymentCredentialsCommand {
        return new MigratePlatformPaymentCredentialsCommand($container, $container->get(ApplicationContext::class));
    }

    public static function makeSignupDiagnostics(ContainerInterface $container): SignupDiagnostics
    {
        return $container->get(DefaultSignupDiagnostics::class);
    }

    public static function makePermissionImplicationSource(ContainerInterface $container): PermissionImplicationSource
    {
        return $container->get(CapabilityCatalog::class);
    }

    public static function makeFormGuard(ContainerInterface $container): DefaultFormGuard
    {
        $context = $container->get(ApplicationContext::class);
        return new DefaultFormGuard(
            $container->get(CacheStore::class),
            rateMax: (int) config($context, 'forms.rate_limit.max', 5),
            rateWindow: (int) config($context, 'forms.rate_limit.window', 60),
        );
    }

    public static function makeFormSealer(ContainerInterface $container): DefaultFormSealer
    {
        $context = $container->get(ApplicationContext::class);
        return new DefaultFormSealer(
            $container->get(EncryptionService::class),
            static fn (array $data): array => FormFieldDerivation::derive($data),
            cacheTtl: (int) config($context, 'render.cache_ttl', 3600),
            maxAge: (int) config($context, 'forms.descriptor_max_age', 1209600),
            buffer: (int) config($context, 'forms.descriptor_buffer', 3600),
            defaultRecipient: (string) config($context, 'forms.default_recipient', ''),
            minSeconds: (int) config($context, 'forms.min_seconds', 2),
        );
    }

    /**
     * Content storage repositories — each resolves Connection (RouteRepository also takes
     * the SEO RedirectRepository so route changes can retire stale redirects).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function repositoryServices(): array
    {
        return [
            BlockTypeRepository::class => [
                'class' => BlockTypeRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationRepository::class => [
                'class' => BlockMigrationRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockInstanceWalker::class => [
                'class' => BlockInstanceWalker::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationGate::class => [
                'class' => BlockMigrationGate::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockRestoreProjector::class => [
                'class' => BlockRestoreProjector::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockUsageScanner::class => [
                'class' => BlockUsageScanner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeRepository::class => [
                'class' => ContentTypeRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryRepository::class => [
                'class' => EntryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            VersionRepository::class => [
                'class' => VersionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            RouteRepository::class => [
                'class' => RouteRepository::class,
                'shared' => true,
                'arguments' => ['@' . Connection::class, '@' . RedirectRepository::class],
            ],
            RedirectRepository::class => [
                'class' => RedirectRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceProjectionRepository::class => [
                'class' => ReferenceProjectionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationRepository::class => [
                'class' => MigrationRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRepository::class => [
                'class' => ScheduleRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * The content engine core and the contract implementations packs bind against
     * (ContentWriter, ContentDeliveryReader, Context, ContentTypeReader), plus
     * schema/validation, publishing, locale, the capability registry, and setup.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function contentEngineServices(): array
    {
        return [
            SetupService::class => [
                'class'    => SetupService::class,
                'shared'   => true,
                'autowire' => true,
            ],
            InstallRoleGrants::class => [
                'class'    => InstallRoleGrants::class,
                'shared'   => true,
                'autowire' => true,
            ],
            FieldTypeRegistry::class => [
                'class'    => DefaultFieldTypeRegistry::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CapabilityRegistry::class => [
                'factory' => [self::class, 'makeCapabilityRegistry'],
                'shared' => true,
            ],
            SchemaProjector::class => [
                'class' => SchemaProjector::class,
                'shared' => false,
                'autowire' => true,
            ],
            FieldValidator::class => [
                'class' => FieldValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentLocaleService::class => [
                'class' => ContentLocaleService::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishService::class => [
                // Factory (not autowire): collects tag-registered thallo.publish_gate services
                // (workflow pack etc.); the tag collection is priority-ordered by the compiler.
                'factory' => [self::class, 'makePublishService'],
                'shared' => true,
            ],
            DraftSummaryReader::class => [
                'class'    => EngineDraftSummaryReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            EntryTargetResolver::class => [
                'class'    => EngineEntryTargetResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            EntryExistenceReader::class => [
                'class'    => EngineEntryExistenceReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Core\Content\Delivery\DeliveryItemShaper::class => [
                'class'    => \Thallo\Core\Content\Delivery\DeliveryItemShaper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Core\Content\Delivery\ListingItemShaper::class => [
                'class'    => \Thallo\Core\Content\Delivery\ListingItemShaper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Delivery\HomepageEntryProvider::class => [
                'class'    => \Thallo\Core\Content\Delivery\EngineHomepageEntryProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Delivery\PublicRouteResolver::class => [
                'class'    => \Thallo\Core\Content\Delivery\EnginePublicRouteResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            FacetCountsReader::class => [
                'class'    => EngineFacetCountsReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            EntryListReader::class => [
                'class'    => EngineEntryListReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Commerce-Slice-2 Fix B: route-independent, tenant-scoped, published-only entry
            // read — the seam Thallo\Render\EntryBlocksRenderer composes to render a
            // route-less linked entry's blocks region (PublicRouteResolver::resolveEntry()
            // requires a live entry_routes row and cannot serve one).
            PublishedEntryBlocksReader::class => [
                'class'    => EnginePublishedEntryBlocksReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ContentWriter::class => [
                'class'    => EngineContentWriter::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ContentDeliveryReader::class => [
                'factory' => [self::class, 'makeContentDeliveryReader'],
                'shared'  => true,
            ],
            IndexableContentReader::class => [
                'factory' => [self::class, 'makeIndexableContentReader'],
                'shared'  => true,
            ],
            Context::class => [
                'class'    => EngineContext::class,
                'shared'   => true,
                'autowire' => true,
            ],
            \Thallo\Contracts\Schema\ContentTypeReader::class => [
                'class'    => \Thallo\Core\Content\Schema\EngineContentTypeReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            MigrationService::class => [
                'class' => MigrationService::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationService::class => [
                'class' => BlockMigrationService::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockBackfillRunner::class => [
                'class' => BlockBackfillRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function starterServices(): array
    {
        $autowired = static fn(string $class): array => [
            'class' => $class,
            'shared' => true,
            'autowire' => true,
        ];

        return [
            \Thallo\Contracts\Starter\StarterContributorRegistry::class => $autowired(
                \Thallo\Core\Content\Starter\DefaultStarterContributorRegistry::class
            ),
            \Thallo\Contracts\Starter\StarterBlockTypeRegistry::class => $autowired(
                \Thallo\Core\Content\Starter\DefaultStarterBlockTypeRegistry::class
            ),
            \Thallo\Core\Content\Starter\Kinds\ContentTypeKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\ContentTypeKind::class
            ),
            \Thallo\Core\Content\Starter\Kinds\BlockTypeKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\BlockTypeKind::class
            ),
            \Thallo\Core\Content\Starter\Kinds\SettingKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\SettingKind::class
            ),
            \Thallo\Core\Content\Starter\Kinds\RegionKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\RegionKind::class
            ),
            \Thallo\Core\Content\Starter\Kinds\NavigationMenuKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\NavigationMenuKind::class
            ),
            \Thallo\Core\Content\Starter\Kinds\HomepageEntryKind::class => $autowired(
                \Thallo\Core\Content\Starter\Kinds\HomepageEntryKind::class
            ),
            \Thallo\Core\Content\Starter\StarterProvenanceRepository::class => $autowired(
                \Thallo\Core\Content\Starter\StarterProvenanceRepository::class
            ),
            \Thallo\Core\Content\Starter\StarterTransaction::class => $autowired(
                \Thallo\Core\Content\Starter\StarterTransaction::class
            ),
            \Thallo\Core\Content\Starter\StarterDefinitions::class => [
                'factory' => [self::class, 'makeStarterDefinitions'],
                'shared' => true,
            ],
            \Thallo\Core\Content\Starter\TenantSeeder::class => $autowired(
                \Thallo\Core\Content\Starter\TenantSeeder::class
            ),
            \Thallo\Core\Content\Starter\StarterSync::class => $autowired(
                \Thallo\Core\Content\Starter\StarterSync::class
            ),
            \Thallo\Core\Content\Starter\DefaultStarterCoverageCheck::class => $autowired(
                \Thallo\Core\Content\Starter\DefaultStarterCoverageCheck::class
            ),
            \Thallo\Tenancy\Contracts\TenantSeedActivator::class => [
                'factory' => [self::class, 'makeTenantSeeder'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\TenantSeedRepair::class => [
                'factory' => [self::class, 'makeTenantSeeder'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\TenantStarterSync::class => [
                'factory' => [self::class, 'makeStarterSync'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\StarterCoverageCheck::class => [
                'factory' => [self::class, 'makeStarterCoverageCheck'],
                'shared' => true,
            ],
            \Thallo\Core\Content\Starter\RawPdoWriteAudit::class => [
                'factory' => [self::class, 'makeRawPdoWriteAudit'],
                'shared' => true,
            ],
            \Thallo\Tenancy\Contracts\StaticWriteAudit::class => [
                'factory' => [self::class, 'makeRawPdoWriteAudit'],
                'shared' => true,
            ],
        ];
    }

    public static function makeStarterDefinitions(
        ContainerInterface $container
    ): \Thallo\Core\Content\Starter\StarterDefinitions {
        return new \Thallo\Core\Content\Starter\StarterDefinitions(
            $container->get(\Thallo\Core\Content\Starter\Kinds\ContentTypeKind::class),
            $container->get(\Thallo\Core\Content\Starter\Kinds\BlockTypeKind::class),
            $container->get(\Thallo\Core\Content\Starter\Kinds\SettingKind::class),
            $container->get(\Thallo\Core\Content\Starter\Kinds\RegionKind::class),
            $container->get(\Thallo\Core\Content\Starter\Kinds\NavigationMenuKind::class),
            $container->get(\Thallo\Core\Content\Starter\Kinds\HomepageEntryKind::class),
        );
    }

    public static function makeTenantSeeder(ContainerInterface $container): \Thallo\Core\Content\Starter\TenantSeeder
    {
        return $container->get(\Thallo\Core\Content\Starter\TenantSeeder::class);
    }

    public static function makeStarterSync(ContainerInterface $container): \Thallo\Core\Content\Starter\StarterSync
    {
        return $container->get(\Thallo\Core\Content\Starter\StarterSync::class);
    }

    public static function makeStarterCoverageCheck(
        ContainerInterface $container
    ): \Thallo\Tenancy\Contracts\StarterCoverageCheck {
        return $container->get(\Thallo\Core\Content\Starter\DefaultStarterCoverageCheck::class);
    }

    public static function makeRawPdoWriteAudit(
        ContainerInterface $container
    ): \Thallo\Core\Content\Starter\RawPdoWriteAudit {
        $context = $container->get(\Glueful\Bootstrap\ApplicationContext::class);
        return new \Thallo\Core\Content\Starter\RawPdoWriteAudit(base_path($context));
    }

    /**
     * Headless SEO/routing: path rendering, route resolution, and canonical-URL
     * projection (the factory-built services derive their config from thallo.seo.*).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function seoServices(): array
    {
        return [
            PathRenderer::class => [
                'factory' => [self::class, 'makePathRenderer'],
                'shared' => true,
            ],
            CanonicalPathBuilder::class => [
                'class' => CanonicalPathBuilder::class,
                'shared' => true,
                'autowire' => true,
            ],
            RootMountGuard::class => [
                'class' => RootMountGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            RouteResolver::class => [
                'class' => RouteResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            CanonicalProjector::class => [
                'factory' => [self::class, 'makeCanonicalProjector'],
                'shared' => true,
            ],
            SeoHeadResolver::class => [
                'factory' => [self::class, 'makeSeoHeadProvider'],
                'shared' => true,
            ],
        ];
    }

    /**
     * Delivery API (published-only read path): the repository, filter/sort compilers,
     * reference resolution (the ReferenceTargetResolver contract binds to the engine's
     * ReferenceFilterResolver), field projection, ETag, the controller, and the
     * delivery-scoped middleware aliases.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function deliveryServices(): array
    {
        return [
            DeliveryRepository::class => [
                'class' => DeliveryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Core\Content\Delivery\HomepageEligibility::class => [
                'class' => \Thallo\Core\Content\Delivery\HomepageEligibility::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Core\Content\Blocks\StarterBlockTypeSeeder::class => [
                'class' => \Thallo\Core\Content\Blocks\StarterBlockTypeSeeder::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler::class => [
                'class' => \Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler::class,
                'shared' => true,
                'autowire' => true,
            ],
            FilterCompiler::class => [
                'class' => FilterCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            SortCompiler::class => [
                'class' => SortCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceResolver::class => [
                'class' => ReferenceResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceFilterResolver::class => [
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceTargetResolver::class => [
                'class' => ReferenceFilterResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            Projector::class => [
                'class' => Projector::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryEtag::class => [
                'class' => DeliveryEtag::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryController::class => [
                'class' => DeliveryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TaxonomyController::class => [
                'class' => TaxonomyController::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryAccessMiddleware::class => [
                'class' => DeliveryAccessMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['delivery_access'],
            ],
            OptionalApiKeyAuthMiddleware::class => [
                'class' => OptionalApiKeyAuthMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['optional_api_key'],
            ],
        ];
    }

    /**
     * Publish-pipeline listeners wired onto the event bus in registerEventListeners().
     * PurgeCdnListener and ReindexSearchListener are capability-gated no-ops in a lean
     * install (no glueful/cdn / content reindexer) — they self-skip at invocation.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function pipelineListenerServices(): array
    {
        return [
            PublishEventEmitter::class => [
                'class' => PublishEventEmitter::class,
                'shared' => true,
                'autowire' => true,
            ],
            InvalidateCacheTagsListener::class => [
                'class' => InvalidateCacheTagsListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishedReferenceRepository::class => [
                'class' => PublishedReferenceRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProjectPublishedReferencesListener::class => [
                'class' => ProjectPublishedReferencesListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            DispatchWebhookListener::class => [
                'class' => DispatchWebhookListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PurgeCdnListener::class => [
                'class' => PurgeCdnListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            SeoMetaChangedListener::class => [
                'class' => SeoMetaChangedListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReindexSearchListener::class => [
                'class' => ReindexSearchListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            CollectionAuditListener::class => [
                'class' => CollectionAuditListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            AnalyticsBridgeListener::class => [
                'class' => AnalyticsBridgeListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            MediaUsageProjector::class => [
                'class' => MediaUsageProjector::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Preview (the narrow draft door). Minter + reader derive the same APP_KEY signing
     * key; the controller wires the admin mint + public token read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function previewServices(): array
    {
        return [
            PreviewMinter::class => [
                'class' => PreviewMinter::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewReader::class => [
                'class' => PreviewReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewSessionVerifier::class => [
                'class' => EnginePreviewSessionVerifier::class,
                'shared' => true,
                'autowire' => true,
            ],
            RichHtmlSanitizer::class => [
                'class' => TipTapHtmlSanitizer::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockEditableFieldResolver::class => [
                'class' => EngineBlockEditableFieldResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            SiteLogoProvider::class => [
                'class'    => EngineSiteLogoProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            SiteFaviconProvider::class => [
                'class'    => EngineSiteFaviconProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Live theme override (theme-setting spec §2): RAW-row provider —
            // the render pack's ActiveThemeSource soft-binds it.
            ThemeSettingProvider::class => [
                'class'    => EngineThemeSettingProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Theme color config (theme-color-config spec §4): saved accent/neutral
            // provider — the render pack's ThemeAppearanceSource soft-binds it.
            ThemeAppearanceProvider::class => [
                'class'    => EngineThemeAppearanceProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            // Global chrome regions (global-regions spec): storage + save
            // validation + the render-pack's soft-bound reader seam.
            RegionRepository::class => [
                'class'    => RegionRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RegionValidator::class => [
                'class'    => RegionValidator::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RegionReader::class => [
                'class'    => EngineRegionReader::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AdminUrlProvider::class => [
                'class'    => EngineAdminUrlProvider::class,
                'shared'   => true,
                'autowire' => true,
            ],
            MediaUrlResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaUrlResolver'],
            ],
            // One object, two interfaces: the batch seam IS the single-url
            // resolver, so the servability predicate cannot drift between them.
            MediaUrlBatchResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaUrlBatchResolver'],
            ],
            MediaVariantUrlResolver::class => [
                'shared' => true,
                'factory' => [self::class, 'makeMediaVariantUrlResolver'],
            ],
            // Factory (not autowire): the theme validator is a SOFT render-pack
            // binding — passed only when present, so core stays removability-clean.
            PreviewController::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewController'],
            ],
            PreviewWorkingCopyStore::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewWorkingCopyStore'],
            ],
        ];
    }

    public static function makePreviewController(ContainerInterface $container): PreviewController
    {
        return new PreviewController(
            $container->get(PreviewMinter::class),
            $container->get(PreviewReader::class),
            $container->get(ContentLocaleService::class),
            $container->get(ApplicationContext::class),
            $container->has(PreviewThemeValidator::class)
                ? $container->get(PreviewThemeValidator::class)
                : null,
        );
    }

    public static function makePreviewWorkingCopyStore(ContainerInterface $container): PreviewWorkingCopyStore
    {
        return new PreviewWorkingCopyStore(
            $container->get(CacheStore::class),
            $container->get(\Thallo\Tenancy\Cache\TenantCacheSegment::class),
            $container->get(ApplicationContext::class),
        );
    }

    public static function makeSystemKeyReconciler(ContainerInterface $container): SystemKeyReconcilerContract
    {
        return $container->get(SystemKeyReconciler::class);
    }

    public static function makeTenantBlobPolicy(ContainerInterface $container): TenantBlobPolicy
    {
        $resolver = $container->has(CurrentTenantResolver::class)
            ? $container->get(CurrentTenantResolver::class)
            : null;

        return new TenantBlobPolicy(
            $container->get(ApplicationContext::class),
            $container->get(Connection::class),
            $container->get(SystemFlags::class),
            $container->get(TenantRuntimeReadiness::class),
            $container->get(WriteBarrier::class),
            $resolver,
            $container->has(\Thallo\Contracts\Tenancy\TenantWriteScope::class)
                ? $container->get(\Thallo\Contracts\Tenancy\TenantWriteScope::class)
                : null,
        );
    }

    public static function makeBlobCreatedHook(ContainerInterface $container): BlobCreatedHook
    {
        return $container->get(TenantBlobPolicy::class);
    }

    public static function makeBlobAccessPolicy(ContainerInterface $container): BlobAccessPolicy
    {
        return $container->get(TenantBlobPolicy::class);
    }

    public static function makeBlobPublicUrlProvider(ContainerInterface $container): BlobPublicUrlProvider
    {
        return new TenantBlobPublicUrlProvider(
            $container->get(ApplicationContext::class),
            $container->get(Connection::class),
            $container->get(SystemFlags::class),
            $container->has(FullTenantResolutionReadiness::class)
                ? $container->get(FullTenantResolutionReadiness::class)
                : null,
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantDomainAdministration::class)
                ? $container->get(TenantDomainAdministration::class)
                : null,
        );
    }

    public static function makeCanonicalPublicOriginResolver(
        ContainerInterface $container
    ): CanonicalPublicOriginResolver {
        return new ThalloCanonicalPublicOriginResolver(
            $container->get(SystemFlags::class),
            $container->has(CurrentTenantResolver::class)
                ? $container->get(CurrentTenantResolver::class)
                : null,
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantDomainAdministration::class)
                ? $container->get(TenantDomainAdministration::class)
                : null,
        );
    }

    public static function makeBlobRouteMiddlewareProvider(
        ContainerInterface $container
    ): BlobRouteMiddlewareProvider {
        return new TenantBlobRouteMiddlewareProvider();
    }

    public static function makeMediaUrlResolver(ContainerInterface $container): EngineMediaUrlResolver
    {
        $context = $container->get(ApplicationContext::class);
        return new EngineMediaUrlResolver(
            $container->get(Connection::class),
            api_prefix($context) . '/blobs',
            (bool) config($context, 'uploads.enabled', true),
            config($context, 'uploads.access', 'private'),
        );
    }

    /** The batch interface resolves to the SHARED MediaUrlResolver instance. */
    public static function makeMediaUrlBatchResolver(ContainerInterface $container): EngineMediaUrlResolver
    {
        return $container->get(MediaUrlResolver::class);
    }

    public static function makeMediaVariantUrlResolver(ContainerInterface $container): EngineMediaVariantUrlResolver
    {
        $context = $container->get(ApplicationContext::class);
        // Candidate-generation gate mirrors UploadController::serveBlob's own check
        // (spec §3): a processor is bound AND uploads.image_processing.enabled. The resolver
        // itself stays bound so its MIME gate still omits invalid media. Incapable → valid
        // images degrade to {src, srcset: null}; never fabricate ?width= URLs.
        $capable = $container->has(MediaProcessorInterface::class)
            && (bool) config($context, 'uploads.image_processing.enabled', true);

        return new EngineMediaVariantUrlResolver(
            $container->get(Connection::class),
            api_prefix($context) . '/blobs',
            (bool) config($context, 'uploads.enabled', true),
            config($context, 'uploads.access', 'private'),
            (int) config($context, 'uploads.image_processing.max_width', 2048),
            $capable,
        );
    }

    /**
     * Full-graph snapshot export/import (the core `thallo.content` engine, tagged for the
     * import-export adapter registry) plus the admin import/export controller.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function importExportServices(): array
    {
        return [
            ContentExporter::class => [
                'class' => ContentExporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.exporter'],
            ],
            ContentImporter::class => [
                'class' => ContentImporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.importer'],
            ],
            ImportExportController::class => [
                'class' => ImportExportController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Background maintenance services: version retention pruning, destructive-schema
     * backfill, and the scheduled publish/unpublish runner.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function maintenanceServices(): array
    {
        return [
            VersionPruner::class => [
                'class' => VersionPruner::class,
                'shared' => true,
                'autowire' => true,
            ],
            BackfillRunner::class => [
                'class' => BackfillRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            FilterIndexJobDispatcher::class => [
                'class' => FilterIndexJobDispatcher::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRunner::class => [
                'class' => ScheduleRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Content-domain HTTP controllers, plus RequirePermission under the
     * `content_permission` container alias — how `->middleware('content_permission:...')`
     * resolves (Router::resolveMiddleware() does container->get('content_permission')).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function contentControllerServices(): array
    {
        return [
            BlockTypeController::class => [
                'class' => BlockTypeController::class,
                'shared' => true,
                'autowire' => true,
            ],
            BlockMigrationController::class => [
                'class' => BlockMigrationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeController::class => [
                'class' => ContentTypeController::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationController::class => [
                'class' => MigrationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryController::class => [
                'class' => EntryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicationController::class => [
                'class' => PublicationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RedirectController::class => [
                'class' => RedirectController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleController::class => [
                'class' => ScheduleController::class,
                'shared' => true,
                'autowire' => true,
            ],
            LocaleAdminController::class => [
                'class' => LocaleAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RequirePermission::class => [
                'factory' => [self::class, 'makeRequirePermission'],
                'shared' => true,
                'alias' => ['content_permission'],
            ],
            PermissionRequirementAuthority::class => [
                'factory' => [self::class, 'makePermissionRequirementAuthority'],
                'shared' => true,
                // Task 8 (admin-commerce-area plan, slice 3): aliased to the neutral
                // Thallo\Contracts\Authorization\PermissionRequirementAuthority contract so a
                // first-party pack (e.g. thallo-commerce's `/meta` endpoint) can depend on the
                // SAME shared instance without referencing this `Thallo\Core\` namespace directly — packs
                // may not depend on the engine app. The alias belongs on THIS (the concrete)
                // definition, not a separate binding for the contract — mirrors
                // packSlugLifecycleAuthorityDefinition()'s identical reasoning in
                // CommerceIntegrationServiceProvider.
                'alias' => [PermissionRequirementAuthorityContract::class],
            ],
            AdminTenantBindingMiddleware::class => [
                'factory' => [self::class, 'makeAdminTenantBinding'],
                'shared' => true,
                'alias' => ['admin_tenant_binding'],
            ],
            RoleMatrix::class => [
                'class' => RoleMatrix::class,
                'shared' => true,
                'autowire' => true,
            ],
            CapabilityCatalog::class => [
                'class' => CapabilityCatalog::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The catalog IS the production PermissionImplicationSource (its `implies`
            // vocabulary drives satisfiersFor()) — bind through a factory that resolves
            // the SAME shared CapabilityCatalog instance rather than a second one.
            PermissionImplicationSource::class => [
                'factory' => [self::class, 'makePermissionImplicationSource'],
                'shared' => true,
            ],
            PolicyManifest::class => [
                'class' => PolicyManifest::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleOverrideRepository::class => [
                'class' => TenantRoleOverrideRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleRepository::class => [
                'class' => TenantRoleRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            BuiltinRoleAvailabilityRepository::class => [
                'class' => BuiltinRoleAvailabilityRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRoleLifecycle::class => [
                'class' => TenantRoleLifecycle::class,
                'shared' => true,
                'autowire' => true,
            ],
            ThalloMembershipRoleAuthority::class => [
                'class' => ThalloMembershipRoleAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
            EffectiveRoleEvaluator::class => [
                'class' => EffectiveRoleEvaluator::class,
                'shared' => true,
                'autowire' => true,
            ],
            EffectiveRoleMatrix::class => [
                'class' => EffectiveRoleMatrix::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRolePolicyMutator::class => [
                'class' => TenantRolePolicyMutator::class,
                'shared' => true,
                'autowire' => true,
            ],
            RolePolicyDiagnostics::class => [
                'class' => RolePolicyDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            RolePolicyDiagnosticsContract::class => [
                'class' => RolePolicyDiagnostics::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantMembershipRoleReader::class => [
                'factory' => [self::class, 'makeMembershipRoleReader'],
                'shared' => true,
            ],
            OperatorBypass::class => [
                'factory' => [self::class, 'makeOperatorBypass'],
                'shared' => true,
            ],
            AuthenticatedPrincipalResolver::class => [
                'class' => AuthenticatedPrincipalResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            PermissionAuthority::class => [
                'class' => PermissionAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeMembershipRoleReader(ContainerInterface $container): TenantMembershipRoleReader
    {
        return new TenantMembershipRoleReader(
            $container->get(ApplicationContext::class),
            $container->has(CurrentTenantResolver::class)
                ? $container->get(CurrentTenantResolver::class)
                : null,
        );
    }

    public static function makeOperatorBypass(ContainerInterface $container): OperatorBypass
    {
        $permissions = $container->has(PermissionManager::class)
            ? $container->get(PermissionManager::class)
            : ($container->has('permission.manager') ? $container->get('permission.manager') : null);
        return new OperatorBypass(
            $container->get(ApplicationContext::class),
            $permissions instanceof PermissionManager ? $permissions : null,
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeAuthorityAudit(ContainerInterface $container): AuthorityAudit
    {
        return new AuthorityAudit(
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeTenancyLifecycleAudit(ContainerInterface $container): TenancyLifecycleAuditContract
    {
        return new TenancyLifecycleAudit(
            $container->has(AuditRecorderInterface::class)
                ? $container->get(AuditRecorderInterface::class)
                : null,
        );
    }

    public static function makeRequirePermission(ContainerInterface $container): RequirePermission
    {
        return new RequirePermission(
            $container->get(ApplicationContext::class),
            $container->get(PermissionRequirementAuthority::class),
        );
    }

    public static function makePermissionRequirementAuthority(
        ContainerInterface $container,
    ): PermissionRequirementAuthority {
        return new PermissionRequirementAuthority(
            $container->get(ApplicationContext::class),
            // Identity implications until a declarative source is bound (the capability
            // catalog becomes the production PermissionImplicationSource).
            $container->has(PermissionImplicationSource::class)
                ? $container->get(PermissionImplicationSource::class)
                : null,
            $container->get(TenantMembershipRoleReader::class),
            $container->get(EffectiveRoleMatrix::class),
            $container->get(OperatorBypass::class),
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
        );
    }

    public static function makeAdminTenantBinding(ContainerInterface $container): AdminTenantBindingMiddleware
    {
        return new AdminTenantBindingMiddleware(
            $container->get(ApplicationContext::class),
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
            $container->has(TenantAdministration::class)
                ? $container->get(TenantAdministration::class)
                : null,
            $container->has(TenantContextRunner::class)
                ? $container->get(TenantContextRunner::class)
                : null,
            $container->has(FullTenantResolutionReadiness::class)
                ? $container->get(FullTenantResolutionReadiness::class)
                : null,
        );
    }

    public static function makeTenancyAccessController(ContainerInterface $container): TenancyAccessController
    {
        return new TenancyAccessController(
            $container->get(AuthenticatedPrincipalResolver::class),
            $container->get(PermissionAuthority::class),
            $container->has(EffectiveRoleMatrix::class)
                ? $container->get(EffectiveRoleMatrix::class)
                : null,
            $container->has(TenantMembershipRoleReader::class)
                ? $container->get(TenantMembershipRoleReader::class)
                : null,
            $container->has(OperatorBypass::class) ? $container->get(OperatorBypass::class) : null,
        );
    }

    /**
     * Platform/admin HTTP controllers (config, users, extensions, media, API keys,
     * settings, cache, health, capabilities, scheduled tasks, setup) and the settings
     * stores they read.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function platformControllerServices(): array
    {
        return [
            AdminConfigController::class => [
                'class' => AdminConfigController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserAdminController::class => [
                'class' => UserAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AssignableRolesController::class => [
                'class' => AssignableRolesController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserRoleAssignmentPolicy::class => [
                'class' => UserRoleAssignmentPolicy::class,
                'shared' => true,
                'autowire' => true,
            ],
            RoleAuthority::class => [
                'class' => RoleAuthority::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityAudit::class => [
                'factory' => [self::class, 'makeAuthorityAudit'],
                'shared' => true,
            ],
            TenancyLifecycleAuditContract::class => [
                'factory' => [self::class, 'makeTenancyLifecycleAudit'],
                'shared' => true,
            ],
            TenantHostCooldownController::class => [
                'class' => TenantHostCooldownController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantRolesController::class => [
                'class' => TenantRolesController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityContinuityGuard::class => [
                'class' => AuthorityContinuityGuard::class,
                'shared' => true,
                'autowire' => true,
            ],
            AuthorityMutator::class => [
                'class' => AuthorityMutator::class,
                'shared' => true,
                'autowire' => true,
            ],
            ExtensionAdminController::class => [
                'class' => ExtensionAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            MediaAdminController::class => [
                'class' => MediaAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantBlobPolicy::class => [
                'factory' => [self::class, 'makeTenantBlobPolicy'],
                'shared' => true,
            ],
            BlobCreatedHook::class => [
                'factory' => [self::class, 'makeBlobCreatedHook'],
                'shared' => true,
            ],
            BlobAccessPolicy::class => [
                'factory' => [self::class, 'makeBlobAccessPolicy'],
                'shared' => true,
            ],
            BlobPublicUrlProvider::class => [
                'factory' => [self::class, 'makeBlobPublicUrlProvider'],
                'shared' => true,
            ],
            CanonicalPublicOriginResolver::class => [
                'factory' => [self::class, 'makeCanonicalPublicOriginResolver'],
                'shared' => true,
            ],
            BlobRouteMiddlewareProvider::class => [
                'factory' => [self::class, 'makeBlobRouteMiddlewareProvider'],
                'shared' => true,
            ],
            ApiKeyAdminController::class => [
                'class' => ApiKeyAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            GeneralSettingsController::class => [
                'class' => GeneralSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RegionAdminController::class => [
                'class' => RegionAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            IconInventoryController::class => [
                'class' => IconInventoryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            SettingsStore::class => [
                'class' => SettingsStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            CapabilityStateStore::class => [
                'class' => CapabilityStateStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The update notice (decision 11): Packagist's public metadata behind the ReleaseFeed
            // seam, the checker wired from config and Composer's installed-version registry.
            ReleaseFeed::class => [
                'class' => PackagistReleaseFeed::class,
                'shared' => true,
            ],
            UpdateChecker::class => [
                'factory' => [self::class, 'makeUpdateChecker'],
                'shared' => true,
            ],
            SchedulerHeartbeat::class => [
                'class' => SchedulerHeartbeat::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec Task 2: the encrypted write/read surface over the
            // unscoped SystemChannel for payvia.* gateway credentials — SystemChannel and
            // EncryptionService both autowire (constructor injection only, no container lookups
            // inside the class itself).
            \Thallo\Core\Settings\PlatformPaymentSettingsStore::class => [
                'class' => \Thallo\Core\Settings\PlatformPaymentSettingsStore::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec Task 3: the TEMPORARY read-only compatibility path
            // over the OLD tenant `settings` table — Task 4's override falls back to it until a
            // migration marker is written, Task 5's migration command drives it for
            // enumeration/verification/pruning. $table is left at its 'settings' default here
            // (autowiring never supplies a scalar); tests that need an isolated temporary table
            // construct the repository directly instead of resolving it from the container.
            \Thallo\Core\Settings\LegacyPlatformPaymentSettingsRepository::class => [
                'class' => \Thallo\Core\Settings\LegacyPlatformPaymentSettingsRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            \Thallo\Core\Settings\LegacyPlatformPaymentSettingsReader::class => [
                'class' => \Thallo\Core\Settings\LegacyPlatformPaymentSettingsReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec §2 (Task 4): payvia's host settings seam, now
            // APP-owned — this replaces thallo-commerce's retired SettingsStorePayviaOverride
            // (deleted in the same change, so no first-wins ambiguity ever existed). Gateway
            // credentials are installation-level infrastructure: the override reads the unscoped
            // system channel first, the temporary legacy compatibility path only while the
            // `payments.platform_credentials_migrated` marker is absent, and has ZERO capability
            // gates.
            //
            // WHY services() AND NOT register(): ExtensionManager::discover() returns early on an
            // extensions-cache hit, so registerProviders() — the only caller of any provider's
            // register() — never runs on the boot mode production is REQUIRED to use, app-level
            // providers included (ProviderClassResolver folds this class into the same list).
            // ContainerFactory::loadExtensionDefinitions() instead re-resolves that list itself
            // while building the container and reads each provider's STATIC services(), which runs
            // identically in both boot modes. Pinned by
            // tests/Integration/Settings/PlatformPayviaOverrideCachedBootTest.
            //
            // Unconditional (unlike the pack's old interface_exists() guard): glueful/payvia is a
            // hard composer requirement of this app, so its interface always autoloads. Disabling
            // the payvia EXTENSION only stops payvia's code from running — it never consults an
            // override it isn't there to read.
            \Glueful\Extensions\Payvia\Support\PayviaSettingsOverride::class => [
                'class' => \Thallo\Core\Settings\PlatformPayviaSettingsOverride::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec §2 (Task 6): the neutral Settings -> Payments API
            // (GET/PUT /v1/admin/settings/payments — see routes/admin.php), replacing
            // thallo-commerce's retired PaymentsSettingsController. Autowired — the constructor's
            // PayviaSettingsOverride param resolves to the SAME shared override bound above, and
            // PlatformPaymentSettingsStore/CanonicalPublicOriginResolver are both already bound.
            PlatformPaymentsSettingsController::class => [
                'class' => PlatformPaymentsSettingsController::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Store-settings spec §3.3: thallo-commerce's pack-owned storage contract, satisfied
            // by SettingsStore rows (pack-defines/app-provides — the EngineMediaUrlResolver shape).
            \Thallo\Commerce\Settings\CommerceSettingsStore::class => [
                'class' => \Thallo\Core\Settings\CommerceSettingsBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Public-account-surface plan Task 3: thallo-account's redirect-settings contract,
            // satisfied by SettingsStore rows (same pack-defines/app-provides shape as commerce).
            \Thallo\Account\Settings\AccountSettingsStore::class => [
                'class' => \Thallo\Core\Settings\AccountSettingsBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Checkout-ui plan Task 3: signed-in email resolution for uncached storefront pages
            // (JWT claims carry no email) — a thin read over the users extension's
            // UserProviderInterface binding; fail-soft to anonymous on any lookup failure.
            \Thallo\Contracts\Account\StorefrontAccountIdentityReader::class => [
                'class' => \Thallo\Core\Account\AppStorefrontAccountIdentityReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Published site pages as convenience redirect targets (public-account-surface plan
            // Task 4, phase 2): pack-defines / app-provides over the delivery layer.
            \Thallo\Contracts\Delivery\PublishedPageDirectory::class => [
                'class' => \Thallo\Core\Content\Delivery\PublishedPageDirectoryBridge::class,
                'shared' => true,
                'autowire' => true,
            ],
            GeneralSettings::class => [
                'class' => GeneralSettings::class,
                'shared' => true,
                'autowire' => true,
            ],
            SystemKeyReconciler::class => [
                'class' => SystemKeyReconciler::class,
                'shared' => true,
                'autowire' => true,
            ],
            SystemKeyReconcilerContract::class => [
                'factory' => [self::class, 'makeSystemKeyReconciler'],
                'shared' => true,
            ],
            CacheAdminController::class => [
                'class' => CacheAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            HealthAdminController::class => [
                'class' => HealthAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UpdateStatusController::class => [
                'class' => UpdateStatusController::class,
                'shared' => true,
                'autowire' => true,
            ],
            CapabilityAdminController::class => [
                'class' => CapabilityAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduledTasksController::class => [
                'class' => ScheduledTasksController::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenancyAccessController::class => [
                'factory' => [self::class, 'makeTenancyAccessController'],
                'shared' => true,
            ],
            SetupController::class => [
                'class' => SetupController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    /**
     * Console commands (also registered via commands() in boot()). Autowire fills each
     * command's BaseCommand (ContainerInterface, ApplicationContext) constructor.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function consoleCommandServices(): array
    {
        return [
            ResyncCommand::class => [
                'class' => ResyncCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            PruneVersionsCommand::class => [
                'class' => PruneVersionsCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            PolicyManifestCommand::class => [
                'class' => PolicyManifestCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SeedBlockTypesCommand::class => [
                'class' => SeedBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SyncBlockTypesCommand::class => [
                'class' => SyncBlockTypesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RetireAccountLinkCommand::class => [
                'class' => RetireAccountLinkCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunBlockBackfillCommand::class => [
                'class' => RunBlockBackfillCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunBackfillCommand::class => [
                'class' => RunBackfillCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunDueSchedulesCommand::class => [
                'class' => RunDueSchedulesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            UpdateCheckCommand::class => [
                'class' => UpdateCheckCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            DoctorCommand::class => [
                'class' => DoctorCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProvisionCommand::class => [
                'class' => ProvisionCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            CreateAdminCommand::class => [
                'class' => CreateAdminCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SuperuserGrantCommand::class => [
                'class' => SuperuserGrantCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            SuperuserTransferCommand::class => [
                'class' => SuperuserTransferCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            // Platform-payments-settings spec §2 "Migration" (Task 5): the conservative cutover of
            // payvia.* credentials from the legacy tenant `settings` table to the unscoped platform
            // system channel. Unlike its neighbours this command declares an EXPLICIT constructor
            // (the platform store, the legacy reader + its repository, and the SystemChannel) so
            // the migration's collaborators are injected rather than looked up — autowiring fills
            // all six parameters, and tests point the legacy repository at an isolated table.
            MigratePlatformPaymentCredentialsCommand::class => [
                // Explicit factory, NOT autowire: its collaborators need the encryption service,
                // which cannot exist before first run; the command resolves them when it runs.
                'factory' => [self::class, 'makeMigratePlatformPaymentCredentialsCommand'],
                'shared' => true,
            ],
        ];
    }

    /** Config files that ship as core/config DEFAULTS (merged below; the root config/ overrides). */
    private const CORE_CONFIG = ['thallo', 'forms', 'signup', 'theme', 'import_export'];

    public function register(ApplicationContext $context): void
    {
        // Thallo's configuration ships as DEFAULTS from core/config: the operator's config/
        // directory holds only overrides, so a new key in a new release reaches every install
        // without touching their files. Root files and environment overlays still win key by
        // key. (tenancy.php and i18n.php stay root files: the framework's tenancy and i18n
        // providers read them before this provider registers.)
        foreach (self::CORE_CONFIG as $name) {
            /** @var array<string,mixed> $defaults */
            $defaults = require self::corePath("config/{$name}.php");
            $this->mergeConfig($name, $defaults);
        }

        // DI bindings are contributed declaratively via services(). The first-run commands
        // register HERE, not in boot(): boot() needs a reachable database, and in production a
        // provider boot failure is logged and skipped — commands registered there vanish exactly
        // when the operator needs doctor/provision to say what is wrong. commands() is a
        // console-only no-op in the HTTP phase.
        $this->commands([
            DoctorCommand::class,
            ProvisionCommand::class,
            CreateAdminCommand::class,
        ]);
    }

    public static function makeCapabilityRegistry(ContainerInterface $container): DefaultCapabilityRegistry
    {
        $context = $container->get(ApplicationContext::class);
        // Requested state comes LIVE from the one system-scoped switchboard
        // (CapabilityStateStore: canonical key → legacy search row → config map → null, and the
        // registry lets an untouched switch follow its engine),
        // memoized inside the registry for this boot — a switchboard write lands on the next
        // request, after the per-boot memo is gone. The store itself fails soft to config on
        // pre-provision boots, so this factory stays safe during CLI boots before the system
        // table exists.
        $switchboard = $container->get(CapabilityStateStore::class);

        return new DefaultCapabilityRegistry(
            [],
            new ExtensionCapabilityAvailabilityResolver($context),
            static fn (string $id): ?bool => $switchboard->explicit($id),
        );
    }

    public static function makePathRenderer(ContainerInterface $container): PathRenderer
    {
        $context = $container->get(ApplicationContext::class);

        return new PathRenderer(
            (string) config($context, 'thallo.seo.route_template', '/{locale}/{type}/{slug}'),
            config($context, 'thallo.seo.public_url_base') === null
                ? null
                : (string) config($context, 'thallo.seo.public_url_base'),
            (string) config($context, 'i18n.default_locale', 'en')
        );
    }

    public static function makePublishService(ContainerInterface $c): PublishService
    {
        $gates = $c->has('thallo.publish_gate') ? $c->get('thallo.publish_gate') : [];
        if ($gates instanceof \Traversable) {
            $gates = iterator_to_array($gates);
        }
        return new PublishService(
            $c->get(ApplicationContext::class),
            $c->get(EntryRepository::class),
            $c->get(VersionRepository::class),
            $c->get(ContentTypeRepository::class),
            $c->get(FieldValidator::class),
            $c->get(ReferenceProjectionRepository::class),
            $c->has(PublishEventEmitter::class) ? $c->get(PublishEventEmitter::class) : null,
            $c->has(SchemaProjector::class) ? $c->get(SchemaProjector::class) : null,
            array_values(array_filter((array) $gates, static fn($g): bool => $g instanceof PublishGate)),
            $c->has(BlockMigrationGate::class) ? $c->get(BlockMigrationGate::class) : null,
            $c->has(BlockRestoreProjector::class) ? $c->get(BlockRestoreProjector::class) : null,
        );
    }

    public static function makeContentDeliveryReader(ContainerInterface $container): EngineContentDeliveryReader
    {
        return new EngineContentDeliveryReader(
            $container->get(DeliveryRepository::class),
            $container->get(CanonicalPathBuilder::class),
            $container->get(CanonicalProjector::class),
            $container->get(ContentTypeRepository::class),
        );
    }

    public static function makeIndexableContentReader(ContainerInterface $container): EngineIndexableContentReader
    {
        return new EngineIndexableContentReader(
            $container->get(DeliveryRepository::class),
            $container->get(CanonicalPathBuilder::class),
        );
    }

    public static function makeCanonicalProjector(ContainerInterface $container): CanonicalProjector
    {
        return new CanonicalProjector(
            $container->get(DeliveryRepository::class),
            $container->get(RouteRepository::class),
            $container->get(ContentTypeRepository::class),
            $container->get(CanonicalPathBuilder::class),
            (string) config($container->get(ApplicationContext::class), 'i18n.default_locale', 'en')
        );
    }

    public static function makeSeoHeadProvider(ContainerInterface $container): EngineSeoHeadProvider
    {
        return new EngineSeoHeadProvider(
            $container->get(ApplicationContext::class),
            $container->get(SeoMetaResolver::class),
            $container->get(CanonicalProjector::class),
            $container->get(CanonicalPublicOriginResolver::class),
            $container->get(HomepageEntryProvider::class),
            $container->get(RouteRepository::class),
            $container->get(ContentTypeRepository::class),
        );
    }

    /**
     * Thallo's capability catalog, declared to the framework's permission registry so
     * `permissions:sync` (and {@see InstallRoleGrants}) persist it into the RBAC provider.
     * Without this, content.manage and friends existed only in {@see CapabilityCatalog} and a
     * route gated on them was a hard 403 for every user, superuser included.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $declared = [];
        foreach ((new CapabilityCatalog())->all() as $slug => $meta) {
            $declared[] = Permission::define($slug)
                ->label($meta['label'])
                ->description($meta['label'])
                ->category($meta['group'])
                ->resource(explode('.', $slug)[0])
                ->managedBy('glueful/thallo');
        }

        return $declared;
    }

    public function boot(ApplicationContext $context): void
    {
        // Thallo's own migrations are declared by core/composer.json's manifest (two lanes,
        // `glueful/thallo-core` and `glueful/thallo-core:dependent`, each naming the source every
        // earlier database recorded its files under as previous_sources), so provision sees them
        // in its first pass and no ledger ever looks pending. The root database/migrations is the
        // operator's own lane — the framework's main path — and needs nothing from here.

        $container = $context->getContainer();
        try {
            $enabled = $container->has(SystemFlags::class)
                && $container->get(SystemFlags::class)->tenancyEnabled();
        } catch (\Throwable) {
            // Pre-provision / unreachable database: tenancy cannot be on, and boot must not
            // die here — the rest of boot (routes, listeners, commands) is still needed.
            $enabled = false;
        }
        self::assertBlobPolicyReady($container, $enabled);

        // Mount the compiled admin SPA at /admin via the framework seam: secure asset serving
        // + index.html deep-link fallback + cache split. No-ops (with a warning) if the bundle
        // is unbuilt. The /admin/config + /admin/setup static routes (routes/admin_spa.php)
        // keep precedence over the SPA catch-all via the router's static-first lookup.
        // Gated by thallo.admin.enabled so an operator can disable the default admin and bring
        // their own (the admin is a replaceable client of the /v1/admin API).
        if ((bool) config($context, 'thallo.admin.enabled', true)) {
            $this->serveFrontend(
                '/admin',
                (string) config($context, 'thallo.admin.bundle_path', self::corePath('resources/admin')),
                ['name' => 'Thallo Admin'],
            );
        }

        // Thallo's routes live under core/ and are loaded here; the root routes/ directory is
        // the operator's and is still auto-discovered by RouteManifest. (Loading a file from
        // BOTH mechanisms would register duplicates and the Router throws — which is why the
        // product's files no longer sit in the discovered directory.)
        foreach (['admin', 'admin_spa', 'content', 'forms', 'preview', 'signup'] as $file) {
            $this->loadRoutesFrom(self::corePath("routes/{$file}.php"));
        }

        $this->registerEventListeners($context);

        // A pack's starter block types appear on the first request after its capability turns
        // on — no provision or seed command. Hooked per request rather than run here: the packs
        // declare their contributions in their OWN boot, which may come after this one. Never
        // fails a request: seeding is a convenience, and `thallo:provision` remains the repair.
        if ($container->has(RequestLifecycle::class)) {
            $container->get(RequestLifecycle::class)->onBeginRequest(
                static function () use ($container): void {
                    try {
                        $container->get(\Thallo\Core\Content\Blocks\ContributedBlockTypeReconciler::class)->reconcile();
                    } catch (\Throwable $e) {
                        if ($container->has(LoggerInterface::class)) {
                            $container->get(LoggerInterface::class)->warning(
                                'Starter block types not reconciled: ' . $e->getMessage(),
                            );
                        }
                    }
                },
            );
        }

        EditorialFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Console: register Thallo's app commands (the first-run set is in register()).
        // commands() is a console-only no-op in the HTTP phase (runningInConsole() guards it).
        $this->commands([
            ResyncCommand::class,
            PruneVersionsCommand::class,
            PolicyManifestCommand::class,
            SeedBlockTypesCommand::class,
            SyncBlockTypesCommand::class,
            RetireAccountLinkCommand::class,
            RunBlockBackfillCommand::class,
            RunBackfillCommand::class,
            RunDueSchedulesCommand::class,
            UpdateCheckCommand::class,
            SuperuserGrantCommand::class,
            SuperuserTransferCommand::class,
            MigratePlatformPaymentCredentialsCommand::class,
        ]);
    }

    public static function assertBlobPolicyReady(ContainerInterface $container, bool $tenancyEnabled): void
    {
        if (!$tenancyEnabled) {
            return;
        }

        if (
            !$container->has(BlobCreatedHook::class)
            || !$container->get(BlobCreatedHook::class) instanceof TenantBlobPolicy
            || !$container->has(BlobAccessPolicy::class)
            || !$container->get(BlobAccessPolicy::class) instanceof TenantBlobPolicy
        ) {
            throw new \RuntimeException('Tenancy is enabled without the tenant blob policy.');
        }
    }

    /**
     * Wire content-pipeline listeners onto the PSR-14 EventService.
     *
     * Listeners are registered lazily by service id ('@' . Listener::class): the
     * dispatcher resolves them from the container on first dispatch and invokes them as
     * callables (so each listener exposes __invoke($event)). This is the shared pattern
     * for every pipeline listener — extend $listeners with [eventClass => [...listeners]].
     */
    private function registerEventListeners(ApplicationContext $context): void
    {
        // addListener() appends with no dedup, so re-running this would double-fire every
        // listener — duplicate webhook deliveries. Refuse to register twice.
        if ($this->listenersRegistered) {
            return;
        }
        $this->listenersRegistered = true;

        $events = app($context, EventService::class);

        // `CoreServiceProvider` (app provider) boots before `AnalyticsServiceProvider`
        // (pack provider), so CapabilityRegistry::isEnabled() would return false for
        // 'thallo.analytics' at this point (the capability is only registered during the pack's
        // own boot()). Read the capabilities override config directly instead — same semantics as
        // DefaultCapabilityRegistry::isEnabled() but without the "must be registered" prerequisite.
        $capOverrides = (array) config($context, 'thallo.capabilities', []);
        $analyticsOn = ($capOverrides['thallo.analytics'] ?? true) === true;

        // event class => list of listener service ids (lazy '@' form).
        //
        // PurgeCdnListener and ReindexSearchListener are CAPABILITY-GATED no-ops in a lean
        // install (no glueful/cdn / content reindexer): they self-skip at invocation, so wiring
        // them broadly is safe. PurgeCdnListener mirrors the cache listener's tag scope (entry
        // + model events, since both move thallo:type:{slug}). ReindexSearchListener is wired to
        // entry LIFECYCLE events only (publish/unpublish/update/delete) — the ones that change a
        // single entry's published index document; model/asset events don't.
        $listeners = [
            // Cache-tag invalidation (V1_DESIGN §5). Entry events drop the entry + type
            // tags; model events drop the type tag. ProjectPublishedReferencesListener
            // runs FIRST (listeners run in array order): the cache purge must see a
            // CURRENT published-reference projection, or a request racing the purge
            // could re-cache stale facet counts until the next event.
            EntryPublished::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUnpublished::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryDeleted::class => [
                ProjectPublishedReferencesListener::class,
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelDeleted::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            // Asset delta events (V1_DESIGN §8) are meaningful to external receivers
            // ("where is this asset used") but carry no cache tags — webhook only.
            AssetAttached::class => [DispatchWebhookListener::class, MediaUsageProjector::class],
            AssetDetached::class => [DispatchWebhookListener::class, MediaUsageProjector::class],
            // SEO override upserts (a clear included) → local + edge purge of the entry's
            // rendered pages (seo-head spec §5). Entry tag only — never type-level tags.
            SeoMetaChanged::class => [
                SeoMetaChangedListener::class,
            ],
        ];

        // Collection row CRUD → audit log + analytics facts. Gated on the pack being INSTALLED
        // (class_exists) so removing the pack drops this wiring cleanly with no dangling reference.
        // CollectionAuditListener is unconditional (installed-gated only): a disabled-but-installed
        // analytics pack must still audit programmatic row mutations. AnalyticsBridgeListener is
        // ENABLED-gated: disabling thallo.analytics hard-stops collection ingestion, consistent with
        // the pack's auth listeners and the read API — no content or collection facts are written
        // while the capability is off (spec §7).
        if (class_exists(CollectionRowCreated::class)) {
            $listeners[CollectionRowCreated::class] = [CollectionAuditListener::class];
            $listeners[CollectionRowUpdated::class] = [CollectionAuditListener::class];
            $listeners[CollectionRowDeleted::class] = [CollectionAuditListener::class];
            $listeners[CollectionCreated::class] = [CollectionAuditListener::class];
            $listeners[CollectionUpdated::class] = [CollectionAuditListener::class];
            $listeners[CollectionDropped::class] = [CollectionAuditListener::class];

            if ($analyticsOn) {
                $listeners[CollectionRowCreated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionRowUpdated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionRowDeleted::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionCreated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionUpdated::class][] = AnalyticsBridgeListener::class;
                $listeners[CollectionDropped::class][] = AnalyticsBridgeListener::class;
            }
        }

        // Content entry events → analytics facts. The analytics bridge is ENABLED-gated: disabling
        // thallo.analytics hard-stops content ingestion, consistent with the pack's auth listeners,
        // the collection block above, and the read API (spec §7). The audit bridge (CollectionAuditListener)
        // remains unconditional/installed-gated and is unaffected by this gate.
        if ($analyticsOn) {
            $listeners[EntryCreated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryUpdated::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryDeleted::class][]    = AnalyticsBridgeListener::class;
            $listeners[EntryPublished::class][]  = AnalyticsBridgeListener::class;
            $listeners[EntryUnpublished::class][] = AnalyticsBridgeListener::class;
        }

        $listeners[DomainReverificationFailed::class][] = DomainReverificationAuditListener::class;
        $listeners[DomainRevoked::class][] = DomainReverificationAuditListener::class;
        $listeners[DomainReverified::class][] = DomainReverificationAuditListener::class;

        foreach ($listeners as $eventClass => $serviceIds) {
            foreach ($serviceIds as $serviceId) {
                $events->addListener($eventClass, '@' . $serviceId);
            }
        }
    }
}
