<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Forms\FieldDef;
use Thallo\Core\Content\Forms\FormDescriptor;
use Thallo\Core\Content\Forms\FormNotifier;
use Thallo\Core\Content\Forms\FormSubmission;
use Thallo\Core\Content\Forms\FormSubmissionRepository;
use Thallo\Core\Content\Forms\FormValueNormalizer;
use Thallo\Core\Content\Forms\Spam\FormSubmissionGuard;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thallo\Contracts\Content\FormSealer;

/**
 * Public form submission (form-block spec §7). The sealed `_form` descriptor is BOTH the
 * authorization and the schema: it is opened here, the guard chain runs against it, and
 * input is validated ONLY against its sealed field list. AJAX and no-JS POSTs share one
 * response path (§7a): a clean submit stores + best-effort emails; a spam reject returns
 * generic success (a bot learns nothing); a validation error returns field errors (AJAX)
 * or a generic failure flag (PRG). The recipient never appears in any response.
 */
final class FormSubmitController
{
    public function __construct(
        private readonly FormSealer $sealer,
        private readonly FormSubmissionGuard $guard,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function submit(Request $request): Response
    {
        $descriptor = $this->sealer->open((string) $request->request->get('_form', ''));
        if (!$descriptor instanceof FormDescriptor) {
            // Tampered, malformed, or expired: the visitor's page is stale.
            return $this->respond(
                $request,
                ok: false,
                descriptor: null,
                error: 'This form expired — reload the page and try again.',
            );
        }

        // Guard chain: silent reject returns generic success so bots can't probe traps.
        $verdict = $this->guard->check($request, $descriptor);
        if (!$verdict->passed()) {
            $this->logger->info('form submission rejected', [
                'form_key' => $descriptor->formKey,
                'reason' => $verdict->reason(),
            ]);
            return $this->respond($request, ok: true, descriptor: $descriptor);
        }

        // Validate + normalize against the SEALED fields only.
        ['values' => $values, 'errors' => $errors] = FormValueNormalizer::normalize(
            $descriptor->fields,
            $request->request->all(),
        );
        if ($errors !== []) {
            return $this->respond($request, ok: false, descriptor: $descriptor, errors: $errors);
        }

        $sourceUrl = $this->safeReturn($request->request->get('_return'))
            ?? (is_string($request->headers->get('Referer')) ? $request->headers->get('Referer') : null);

        // Delivery mode is sealed (server-side only): email_only skips storage entirely.
        if ($descriptor->shouldStore()) {
            $this->submissions->store(new FormSubmission(
                uuid: '',
                formKey: $descriptor->formKey,
                formName: $descriptor->formName,
                sourceUrl: $sourceUrl,
                fieldsSnapshot: array_map(static fn (FieldDef $f): array => $f->toArray(), $descriptor->fields),
                values: $values,
                descriptorVersion: $descriptor->v,
                status: 'unread',
                ip: $request->getClientIp(),
                userAgent: substr((string) $request->headers->get('User-Agent', ''), 0, 512) ?: null,
                submittedAt: gmdate('Y-m-d H:i:s'),
            ));
        }

        // Best-effort email (spec §10): never fatal. For email_only this is the only sink,
        // so it still runs even though nothing was stored.
        $this->notifier->notify($descriptor, $values, $sourceUrl);

        return $this->respond($request, ok: true, descriptor: $descriptor);
    }

    /**
     * Unify AJAX (flat JSON) and no-JS (PRG 303) responses (spec §7a). Guard reasons are
     * NEVER surfaced — only a generic form_ok/form_err flag rides the PRG redirect.
     *
     * @param array<string,string>|null $errors
     */
    private function respond(
        Request $request,
        bool $ok,
        ?FormDescriptor $descriptor,
        ?array $errors = null,
        ?string $error = null,
    ): Response {
        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return new JsonResponse($ok
                ? ['ok' => true, 'message' => $descriptor?->successMessage ?? 'Thanks — your message has been sent.']
                : [
                    'ok' => false,
                    'errors' => $errors ?? [],
                    'error' => $error ?? 'Please check your entries and try again.',
                ]);
        }

        // No-JS PRG. Success with a sealed redirect_url (already safe) wins outright.
        if ($ok && $descriptor?->redirectUrl !== null && $descriptor?->redirectUrl !== '') {
            return new RedirectResponse($descriptor->redirectUrl, 303);
        }
        $return = $this->safeReturn($request->request->get('_return')) ?? '/';
        $flag = $ok ? 'form_ok' : 'form_err';
        $key = $descriptor?->formKey ?? '';
        $sep = str_contains($return, '?') ? '&' : '?';
        return new RedirectResponse($return . $sep . $flag . '=' . urlencode($key), 303);
    }

    /** Same internal-only rule as the sealer's safeRedirect: a single leading slash only. */
    private function safeReturn(mixed $url): ?string
    {
        if (!is_string($url) || $url === '') {
            return null;
        }
        return preg_match('#\A/(?!/)[^\s]*\z#', $url) === 1 ? $url : null;
    }
}
