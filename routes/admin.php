<?php

declare(strict_types=1);

use Thallo\Core\Content\Http\Controllers\BlockMigrationController;
use Thallo\Core\Content\Http\Controllers\BlockTypeController;
use Thallo\Core\Content\Http\Controllers\ContentTypeController;
use Thallo\Core\Content\Http\Controllers\EntryController;
use Thallo\Core\Content\Http\Controllers\LocaleAdminController;
use Thallo\Core\Content\Http\Controllers\MigrationController;
use Thallo\Core\Content\Http\Controllers\PreviewController;
use Thallo\Core\Content\Http\Controllers\PublicationController;
use Thallo\Core\Content\Http\Controllers\RedirectController;
use Thallo\Core\Content\Http\Controllers\ScheduleController;
use Thallo\Core\Http\Controllers\ApiKeyAdminController;
use Thallo\Core\Http\Controllers\AssignableRolesController;
use Thallo\Core\Http\Controllers\CacheAdminController;
use Thallo\Core\Http\Controllers\CapabilityAdminController;
use Thallo\Core\Http\Controllers\ExtensionAdminController;
use Thallo\Core\Http\Controllers\FormSubmissionsController;
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
use Glueful\Api\Webhooks\Http\Controllers\WebhookController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin authoring API. Every route is gated by the `auth` middleware (a Bearer JWT or an
 * API key resolves the principal) PLUS a `content_permission:<permission>` RBAC check. The
 * required permission is named per route in its @description. Auto-discovered by
 * RouteManifest; the provider must NOT loadRoutesFrom() this file.
 */
$router->group(['prefix' => '/v1/admin'], function (Router $router): void {
    $router->group([
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ], function (Router $router): void {
        $router->get('/tenancy/signup/members', [SignupController::class, 'memberSettings'])
            ->middleware('content_permission:tenant.members.manage');
        $router->put('/tenancy/signup/members', [SignupController::class, 'updateMemberSettings'])
            ->middleware('content_permission:tenant.members.manage');
        $router->get('/tenancy/roles', [TenantRolesController::class, 'index'])
            ->middleware('content_permission:tenant.roles.manage');
        $router->put('/tenancy/roles/{slug}/overrides', [TenantRolesController::class, 'overrides'])
            ->middleware('content_permission:tenant.roles.manage');
        $router->post('/tenancy/roles/preview', [TenantRolesController::class, 'preview'])
            ->middleware('content_permission:tenant.roles.manage');
        $router->get('/tenancy/roles/assignable', [TenantRolesController::class, 'assignable'])
            ->middleware('content_permission:tenant.members.manage');
        $router->post('/tenancy/roles', [TenantRolesController::class, 'create'])
            ->middleware('content_permission:tenant.roles.manage');
        $router->patch('/tenancy/roles/{slug}', [TenantRolesController::class, 'update'])
            ->middleware('content_permission:tenant.roles.manage');
        $router->delete('/tenancy/roles/{slug}', [TenantRolesController::class, 'delete'])
            ->middleware('content_permission:tenant.roles.manage');
    // Content type (model) management.
        $router->get('/content-types', [ContentTypeController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('content_permission:content.manage');

        $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('content_permission:content.view');

        $router->patch('/content-types/{slug}', [ContentTypeController::class, 'update'])
        ->middleware('content_permission:content.manage');

        $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('content_permission:content.manage');

    // Destructive schema migrations: POST body is {ops:[{op:"rename",from,to}|{op:"delete",name}]};
    // responses wrap migration rows with status/progress/failure_report for polling.
        $router->post('/content-types/{slug}/migrations', [MigrationController::class, 'store'])
        ->middleware('content_permission:content.manage');

        $router->get('/content-types/{slug}/migrations', [MigrationController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->get('/content-types/{slug}/migrations/{migrationUuid}', [MigrationController::class, 'show'])
        ->middleware('content_permission:content.view');

        $router->delete('/content-types/{slug}', [ContentTypeController::class, 'destroy'])
        ->middleware('content_permission:content.manage');

    // Block-type registry (block-builder spec §1): the reusable block schemas that
    // `blocks` fields compose. Same permissions as content-type schema management.
        $router->get('/block-types', [BlockTypeController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->post('/block-types', [BlockTypeController::class, 'store'])
        ->middleware('content_permission:content.manage');

        $router->get('/block-types/{slug}', [BlockTypeController::class, 'show'])
        ->middleware('content_permission:content.view');

        $router->patch('/block-types/{slug}', [BlockTypeController::class, 'update'])
        ->middleware('content_permission:content.manage');

        $router->post('/block-types/{slug}/activate', [BlockTypeController::class, 'activate'])
        ->middleware('content_permission:content.manage');

        $router->post('/block-types/{slug}/deactivate', [BlockTypeController::class, 'deactivate'])
        ->middleware('content_permission:content.manage');

    // Block-type schema migrations (block-migrations spec §2): declared rename/delete
    // ops with an eager queued backfill; one active migration per type.
        $router->post('/block-types/{slug}/migrations', [BlockMigrationController::class, 'store'])
        ->middleware('content_permission:content.manage');

        $router->get('/block-types/{slug}/migrations', [BlockMigrationController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->get('/block-types/{slug}/migrations/{migrationUuid}', [BlockMigrationController::class, 'show'])
        ->middleware('content_permission:content.view');

    // Usage scan + zero-usage hard delete (block-migrations spec §6).
        $router->get('/block-types/{slug}/usage', [BlockTypeController::class, 'usage'])
        ->middleware('content_permission:content.view');

        $router->delete('/block-types/{slug}', [BlockTypeController::class, 'destroy'])
        ->middleware('content_permission:content.manage');

    // Entry authoring (identity, drafts, preview).
        $router->get('/entries', [EntryController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->post('/entries', [EntryController::class, 'store'])
        ->middleware('content_permission:content.create');

        $router->get('/entries/{uuid}', [EntryController::class, 'show'])
        ->middleware('content_permission:content.view');

        $router->get('/entries/{uuid}/draft/{locale}', [EntryController::class, 'getDraft'])
        ->middleware('content_permission:content.view');

        $router->put('/entries/{uuid}/draft/{locale}', [EntryController::class, 'saveDraft'])
        ->middleware('content_permission:content.edit');

        $router->delete('/entries/{uuid}/draft/{locale}', [EntryController::class, 'discardDraft'])
        ->middleware('content_permission:content.edit');

        $router->delete('/entries/{uuid}', [EntryController::class, 'destroy'])
        ->middleware('content_permission:content.delete');

        $router->get('/entries/{uuid}/locales', [EntryController::class, 'locales'])
        ->middleware('content_permission:content.view');

        $router->post('/entries/{uuid}/locales/{locale}', [EntryController::class, 'createLocaleDraft'])
        ->middleware('content_permission:content.create');

    // Per-locale content usage counts — used to warn before disabling a language.
        $router->get('/locales/{locale}/usage', [LocaleAdminController::class, 'usage'])
        ->middleware('content_permission:content.manage');

        $router->get('/entries/{uuid}/versions/{locale}', [PublicationController::class, 'versions'])
        ->middleware('content_permission:content.view');

        $router->get('/entries/{uuid}/routes', [EntryController::class, 'routes'])
        ->middleware('content_permission:content.view');

        $router->put('/entries/{uuid}/routes/{locale}', [EntryController::class, 'assignRoute'])
        ->middleware('content_permission:content.edit');

        $router->delete('/entries/{uuid}/routes/{locale}', [EntryController::class, 'removeRoute'])
        ->middleware('content_permission:content.edit');

    // SEO redirects: POST body is {locale, source_slug, target:{url|entry_uuid, content_type?, locale?}, status};
    // responses wrap redirect rows and their computed target_state (live|broken).
        $router->post('/content-types/{slug}/redirects', [RedirectController::class, 'store'])
        ->middleware('content_permission:content.routes');

        $router->get('/content-types/{slug}/redirects', [RedirectController::class, 'index'])
        ->middleware('content_permission:content.routes');

        $router->delete('/redirects/{uuid}', [RedirectController::class, 'destroy'])
        ->middleware('content_permission:content.routes');

        $router->post('/entries/{uuid}/preview/{locale}', [PreviewController::class, 'mint'])
        ->middleware('content_permission:content.view');

    // Loop C: apply the CURRENT working fields as an ephemeral preview. Reveals
    // UNSAVED edits through the preview token, so it takes the editor's permission.
        $router->post('/entries/{uuid}/preview/{locale}/apply', [EntryController::class, 'applyPreview'])
        ->middleware('content_permission:content.edit');

    // Scheduled publication: POST body is {action:"publish"|"unpublish", run_at:<absolute ISO-8601 with timezone>};
    // response wraps {schedule:{...row,replaced:bool}}. GET returns {schedules:[...history]}.
        $router->post('/entries/{uuid}/schedules/{locale}', [ScheduleController::class, 'store'])
        ->middleware('content_permission:content.publish');

        $router->get('/entries/{uuid}/schedules', [ScheduleController::class, 'index'])
        ->middleware('content_permission:content.view');

        $router->delete('/entries/{uuid}/schedules/{scheduleUuid}', [ScheduleController::class, 'destroy'])
        ->middleware('content_permission:content.publish');

    // Publication lifecycle.
        $router->post('/entries/{uuid}/publish/{locale}', [PublicationController::class, 'publish'])
        ->middleware('content_permission:content.publish');

        $router->post('/entries/{uuid}/unpublish/{locale}', [PublicationController::class, 'unpublish'])
        ->middleware('content_permission:content.publish');

        $router->post('/entries/{uuid}/rollback/{locale}', [PublicationController::class, 'rollback'])
        ->middleware('content_permission:content.publish');

    // Email settings + templates moved to the glueful/email-notification extension's own
    // API (/email/settings, /email/templates — root-mounted, gated email.templates.manage).
    // The old .env-writing controller retired with the DB-backed settings store.
    });

    $router->group(['middleware' => ['tenant_system', 'auth']], function (Router $router): void {
        // Vendored icon inventory for the admin icon picker.
        $router->get('/icons', [IconInventoryController::class, 'index'])
            ->middleware('content_permission:content.view');
    });

    $router->group([
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ], function (Router $router): void {
        // Global chrome regions (header/footer block lists) — chrome is content policy.
        $router->get('/regions', [RegionAdminController::class, 'index'])
            ->middleware('content_permission:content.view');

    // Renders UNSAVED region payloads through the real theme pipeline (never writes).
        $router->post('/regions/preview', [RegionAdminController::class, 'preview'])
        ->middleware('content_permission:content.view');

        $router->put('/regions/{slug}', [RegionAdminController::class, 'update'])
        ->middleware('content_permission:content.manage');

    // Form submissions triage (form-block spec §11). Static routes (unread-count,
    // export.csv) precede the {uuid} routes; the router resolves static first anyway.
        $router->get('/form-submissions', [FormSubmissionsController::class, 'index'])
        ->middleware('content_permission:content.manage');

        $router->get('/form-submissions/unread-count', [FormSubmissionsController::class, 'unreadCount'])
        ->middleware('content_permission:content.manage');

        $router->get('/form-submissions/export.csv', [FormSubmissionsController::class, 'export'])
        ->middleware('content_permission:content.manage');

        $router->get('/form-submissions/{uuid}', [FormSubmissionsController::class, 'show'])
        ->middleware('content_permission:content.manage');

        $router->patch('/form-submissions/{uuid}/read', [FormSubmissionsController::class, 'read'])
        ->middleware('content_permission:content.manage');

        $router->delete('/form-submissions/{uuid}', [FormSubmissionsController::class, 'destroy'])
        ->middleware('content_permission:content.manage');

    // Instance General settings — site identity, default locale, delivery defaults, feature toggles
    // (persisted as env keys in .env).
        $router->get('/settings/general', [GeneralSettingsController::class, 'show'])
        ->middleware('content_permission:content.manage');

        $router->put('/settings/general', [GeneralSettingsController::class, 'update'])
            ->middleware('content_permission:content.manage');
    });

    $router->group(['middleware' => ['tenant_system', 'auth']], function (Router $router): void {
        // Admin user management (app-owned policy over glueful/users' store primitives). The list/read
        // lives in glueful/users (`GET /v1/users`); creating and removing users is product policy.
        $router->get('/users/assignable-roles', [AssignableRolesController::class, 'index'])
            ->middleware('content_permission:users.roles.manage');

        $router->post('/users', [UserAdminController::class, 'store'])
            ->middleware('content_permission:users.create');

        $router->patch('/users/{uuid}', [UserAdminController::class, 'update'])
        ->middleware('content_permission:users.edit');

        $router->delete('/users/{uuid}', [UserAdminController::class, 'destroy'])
        ->middleware('content_permission:users.delete');

    // Extensions — list/toggle installed glueful-extension packages + browse the Packagist catalog.
    // Enable/disable rewrites config/extensions.php (dev only). All gated by system.access.
        $router->get('/extensions', [ExtensionAdminController::class, 'index'])
        ->middleware('content_permission:system.access');

        $router->get('/extensions/registry', [ExtensionAdminController::class, 'registry'])
        ->middleware('content_permission:system.access');

        $router->post('/extensions/enable', [ExtensionAdminController::class, 'enable'])
        ->middleware('content_permission:system.access');

        $router->post('/extensions/disable', [ExtensionAdminController::class, 'disable'])
        ->middleware('content_permission:system.access');

    // Install a new extension via composer (synchronous; the request blocks until composer finishes).
        $router->post('/extensions/install', [ExtensionAdminController::class, 'install'])
        ->middleware('content_permission:system.access');

        $router->get('/extensions/{vendor}/{name}/readme', [ExtensionAdminController::class, 'readme'])
            ->middleware('content_permission:system.access');
    });

    $router->group([
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ], function (Router $router): void {
        // Media library — list/search over blobs + CMS metadata (alt/caption/tags) + usage.
        $router->get('/media', [MediaAdminController::class, 'index'])
            ->middleware('content_permission:content.view');

        $router->get('/media/{uuid}', [MediaAdminController::class, 'show'])
        ->middleware('content_permission:content.view');

        $router->get('/media/{uuid}/usage', [MediaAdminController::class, 'usage'])
        ->middleware('content_permission:content.view');

        $router->post('/media/{uuid}/optimize', [MediaAdminController::class, 'optimize'])
        ->middleware('content_permission:content.manage');

        $router->patch('/media/{uuid}', [MediaAdminController::class, 'update'])
        ->middleware('content_permission:content.manage');

        $router->delete('/media/{uuid}', [MediaAdminController::class, 'destroy'])
            ->middleware('content_permission:content.manage');
    });

    $router->group(['middleware' => ['tenant_system', 'auth']], function (Router $router): void {
        $router->get('/settings/signup', [SignupController::class, 'singleStoreMemberSettings'])
            ->middleware('content_permission:users.roles.manage');
        $router->put('/settings/signup', [SignupController::class, 'updateSingleStoreMemberSettings'])
            ->middleware('content_permission:users.roles.manage');
        $router->get('/settings/signup/roles', [TenantRolesController::class, 'index'])
            ->middleware('content_permission:users.roles.manage');
        $router->put('/settings/signup/roles/{slug}/overrides', [TenantRolesController::class, 'overrides'])
            ->middleware('content_permission:users.roles.manage');
        $router->post('/settings/signup/roles/preview', [TenantRolesController::class, 'preview'])
            ->middleware('content_permission:users.roles.manage');
        $router->post('/settings/signup/roles', [TenantRolesController::class, 'create'])
            ->middleware('content_permission:users.roles.manage');
        $router->patch('/settings/signup/roles/{slug}', [TenantRolesController::class, 'update'])
            ->middleware('content_permission:users.roles.manage');
        $router->delete('/settings/signup/roles/{slug}', [TenantRolesController::class, 'delete'])
            ->middleware('content_permission:users.roles.manage');

        // API keys — system-wide list/create/rotate/revoke over the framework `api_keys` store. The
        // plaintext key is returned only on create/rotate. All gated by system.access.
        $router->get('/api-keys', [ApiKeyAdminController::class, 'index'])
            ->middleware('content_permission:system.access');

        $router->post('/api-keys', [ApiKeyAdminController::class, 'store'])
        ->middleware('content_permission:system.access');

        $router->get('/api-keys/{uuid}', [ApiKeyAdminController::class, 'show'])
        ->middleware('content_permission:system.access');

        $router->post('/api-keys/{uuid}/rotate', [ApiKeyAdminController::class, 'rotate'])
        ->middleware('content_permission:system.access');

        $router->patch('/api-keys/{uuid}/scopes', [ApiKeyAdminController::class, 'updateScopes'])
        ->middleware('content_permission:system.access');

        $router->patch('/api-keys/{uuid}/tenant', [ApiKeyAdminController::class, 'updateTenant'])
            ->middleware([
                'content_permission:system.access',
                'content_permission:tenancy.manage',
            ]);

        $router->delete('/api-keys/{uuid}', [ApiKeyAdminController::class, 'destroy'])
            ->middleware('content_permission:system.access');
    });

    $router->group(['middleware' => ['tenant_system', 'auth']], function (Router $router): void {
        // Webhooks — surface the framework's webhook engine (subscriptions + deliveries) in the admin.
        // Routes delegate to the framework's WebhookController; the tables are materialized by the
        // 007_CreateWebhookTables migration so listing works before the first dispatch. All gated by
        // system.access.
        $router->get('/webhooks/subscriptions', [WebhookController::class, 'listSubscriptions'])
            ->middleware('content_permission:system.access');

        $router->post('/webhooks/subscriptions', [WebhookController::class, 'createSubscription'])
        ->middleware('content_permission:system.access');

        $router->get('/webhooks/subscriptions/{id}', [WebhookController::class, 'getSubscription'])
        ->middleware('content_permission:system.access');

        $router->patch('/webhooks/subscriptions/{id}', [WebhookController::class, 'updateSubscription'])
        ->middleware('content_permission:system.access');

        $router->delete('/webhooks/subscriptions/{id}', [WebhookController::class, 'deleteSubscription'])
        ->middleware('content_permission:system.access');

        $router->post('/webhooks/subscriptions/{id}/rotate-secret', [WebhookController::class, 'rotateSecret'])
        ->middleware('content_permission:system.access');

        $router->post('/webhooks/subscriptions/{id}/test', [WebhookController::class, 'testSubscription'])
        ->middleware('content_permission:system.access');

        $router->get('/webhooks/subscriptions/{id}/stats', [WebhookController::class, 'getSubscriptionStats'])
        ->middleware('content_permission:system.access');

        $router->get('/webhooks/deliveries', [WebhookController::class, 'listDeliveries'])
        ->middleware('content_permission:system.access');

        $router->get('/webhooks/deliveries/{id}', [WebhookController::class, 'getDelivery'])
        ->middleware('content_permission:system.access');

        $router->post('/webhooks/deliveries/{id}/retry', [WebhookController::class, 'retryDelivery'])
            ->middleware('content_permission:system.access');
    });

    $router->group(['middleware' => ['tenant_system', 'auth']], function (Router $router): void {
        // Capabilities — reports installed packs whose capability is enabled by the switchboard.
        // Read-only DISCOVERY for the admin SPA (which modules exist), deliberately gated by
        // auth alone — NOT `system.access`: a workspace owner with e.g. `navigation.manage`
        // must be able to discover the Navigation module without system-operator rights.
        // It leaks only pack availability, never data; every actual feature endpoint keeps
        // its own `content_permission:*` gate.
        $router->get('/capabilities', [CapabilityAdminController::class, 'index']);

        // The operator switchboard (schema program Task 7): every registered capability with
        // requested/availability/effective state, and the requested-state flip. Operator-only —
        // unlike the discovery feed above, these carry `system.access`. The PUT is pure
        // bearer-auth like every admin mutation (no cookie, no CSRF surface).
        $router->get('/capabilities/manage', [CapabilityAdminController::class, 'manage'])
            ->middleware('content_permission:system.access');
        $router->put('/capabilities/{id}', [CapabilityAdminController::class, 'update'])
            ->middleware('content_permission:system.access');

    // Utilities — system ops tools (Health, Cache, Scheduled tasks). All gated by system.access.
        $router->get('/health', [HealthAdminController::class, 'show'])
        ->middleware('content_permission:system.access');

        $router->get('/update-status', [UpdateStatusController::class, 'show'])
        ->middleware('content_permission:system.access');

        $router->get('/cache', [CacheAdminController::class, 'show'])
        ->middleware('content_permission:system.access');

        $router->post('/cache/clear', [CacheAdminController::class, 'clear'])
        ->middleware('content_permission:system.access');

        $router->get('/scheduled-tasks', [ScheduledTasksController::class, 'index'])
        ->middleware('content_permission:system.access');

        $router->post('/scheduled-tasks/{name}/run', [ScheduledTasksController::class, 'run'])
            ->middleware('content_permission:system.access');
    });

    $router->group([
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ], function (Router $router): void {
        // Import/Export: the glueful/import-export extension owns the job API (under /import-export), but
        // ships no route to download an export result or upload an import file — these Thallo routes fill
        // both gaps (the importer reads from the uploads disk; see config/import_export.php source_roots).
        $router->get('/import-export/jobs/{uuid}/download', [ImportExportController::class, 'download'])
            ->where('uuid', '[A-Za-z0-9_-]+')
            ->middleware('content_permission:content.manage');

        $router->post('/import-export/upload', [ImportExportController::class, 'upload'])
            ->middleware('content_permission:content.manage');
    });

    $router->get('/tenancy/access', [TenancyAccessController::class, 'access'])
        ->middleware('auth')
        ->middleware('tenant_profile:admin,soft')
        ->middleware('tenant_bootstrap:optional');

    $router->post('/tenancy/hosts/cooldown/override', [TenantHostCooldownController::class, 'override'])
        ->middleware('auth')
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');

    $router->post('/tenancy/roles/{tenant}/reset', [TenantRolesController::class, 'reset'])
        ->middleware('auth')
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');

    $router->get('/tenancy/signup/workspaces', [SignupController::class, 'workspaceSettings'])
        ->middleware('auth')
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');
    $router->put('/tenancy/signup/workspaces', [SignupController::class, 'updateWorkspaceSettings'])
        ->middleware('auth')
        ->middleware('tenant_system')
        ->middleware('content_permission:tenancy.manage');

    // Platform-payments-settings spec §2 (Task 6): the neutral Settings -> Payments API,
    // replacing thallo-commerce's retired `/v1/admin/commerce/payments` (commerce-owned
    // SettingsStore -> app-owned PlatformPaymentSettingsStore, commerce.manage ->
    // tenancy.manage). Gateway credentials are platform/installation-level infrastructure
    // (see Thallo\Core\Settings\PlatformPaymentSettingsStore's own docblock), so this sits at the
    // platform-operator authority tier — the same `['auth', 'tenant_system',
    // 'content_permission:tenancy.manage']` group thallo-subscriptions' admin surface uses —
    // never gated by any pack capability.
    $router->group([
        'middleware' => ['auth', 'tenant_system', 'content_permission:tenancy.manage'],
    ], function (Router $router): void {
        $router->get('/settings/payments', [PlatformPaymentsSettingsController::class, 'show'])
            ->name('thallo.settings.payments.show');
        $router->put('/settings/payments', [PlatformPaymentsSettingsController::class, 'update'])
            ->name('thallo.settings.payments.update');
    });
});
