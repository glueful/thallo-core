<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Forms\FormSubmission;
use Thallo\Core\Content\Forms\FormSubmissionRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin triage for stored form submissions (form-block spec §11). Gated `content.manage`
 * at the route. List/detail/read/delete plus a CSV export whose columns are the fixed
 * metadata set unioned with every field key seen across the filtered rows, so a form with
 * extra fields still exports losslessly.
 */
final class FormSubmissionsController
{
    /** Fixed metadata columns that precede the per-field value columns in a CSV export. */
    private const META_COLUMNS = ['submitted_at', 'form_name', 'source_url', 'ip', 'user_agent'];

    public function __construct(
        private readonly FormSubmissionRepository $repository,
    ) {
    }

    /** GET /v1/admin/form-submissions?form_key=&status= */
    #[ApiOperation(
        summary: 'List form submissions',
        description: 'Stored contact-form submissions newest-first, optionally filtered by '
            . '`form_key` and `status` (unread|read). Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'Submission summaries.')]
    public function index(Request $request): Response
    {
        $filter = $this->filterFrom($request);
        $rows = array_map(
            static fn (FormSubmission $s): array => [
                'uuid' => $s->uuid,
                'form_key' => $s->formKey,
                'form_name' => $s->formName,
                'source_url' => $s->sourceUrl,
                'status' => $s->status,
                'submitted_at' => $s->submittedAt,
            ],
            $this->repository->list($filter),
        );
        return Response::success(['submissions' => $rows], 'Submissions retrieved.');
    }

    /** GET /v1/admin/form-submissions/{uuid} */
    #[ApiOperation(
        summary: 'Read one submission',
        description: 'The full submission: the sealed field snapshot (labels/types the visitor saw) '
            . 'and the normalized values. Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'The submission.')]
    #[ApiResponse(404, description: 'Unknown submission.')]
    public function show(string $uuid): Response
    {
        $submission = $this->repository->find($uuid);
        if ($submission === null) {
            return Response::notFound('Submission not found.');
        }
        return Response::success(['submission' => $submission->toArray()], 'Submission retrieved.');
    }

    /** PATCH /v1/admin/form-submissions/{uuid}/read */
    #[ApiOperation(
        summary: 'Mark a submission read',
        description: 'Flips status to `read` (idempotent). Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'Marked read.')]
    #[ApiResponse(404, description: 'Unknown submission.')]
    public function read(string $uuid): Response
    {
        if ($this->repository->find($uuid) === null) {
            return Response::notFound('Submission not found.');
        }
        $this->repository->markRead($uuid);
        return Response::success(['uuid' => $uuid, 'status' => 'read'], 'Submission marked read.');
    }

    /** DELETE /v1/admin/form-submissions/{uuid} */
    #[ApiOperation(
        summary: 'Delete a submission',
        description: 'Permanently removes one submission. Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'Deleted.')]
    #[ApiResponse(404, description: 'Unknown submission.')]
    public function destroy(string $uuid): Response
    {
        if ($this->repository->find($uuid) === null) {
            return Response::notFound('Submission not found.');
        }
        $this->repository->delete($uuid);
        return Response::success(['uuid' => $uuid], 'Submission deleted.');
    }

    /** GET /v1/admin/form-submissions/unread-count */
    #[ApiOperation(
        summary: 'Unread submission count',
        description: 'The number of unread submissions, for the sidebar badge. Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'The unread count.')]
    public function unreadCount(): Response
    {
        return Response::success(['count' => $this->repository->unreadCount()], 'Unread count retrieved.');
    }

    /** GET /v1/admin/form-submissions/export.csv?form_key=&status= */
    #[ApiOperation(
        summary: 'Export submissions as CSV',
        description: 'Streams the filtered submissions as CSV: fixed metadata columns unioned with '
            . 'every field key seen across the rows. Requires `content.manage`.',
        tags: ['Thallo Forms'],
    )]
    #[ApiResponse(200, description: 'A text/csv attachment.')]
    public function export(Request $request): HttpResponse
    {
        $rows = $this->repository->list($this->filterFrom($request));

        // Union of field keys across all rows so a form with extra fields exports losslessly.
        $fieldKeys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row->values) as $key) {
                $fieldKeys[(string) $key] = true;
            }
        }
        $fieldKeys = array_keys($fieldKeys);
        $header = array_merge(self::META_COLUMNS, $fieldKeys);

        $response = new StreamedResponse(static function () use ($rows, $header, $fieldKeys): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            // PHP 8.4: $escape must be explicit ('' = no special escaping, RFC-4180 quoting only).
            fputcsv($out, $header, ',', '"', '');
            foreach ($rows as $row) {
                $line = [
                    $row->submittedAt,
                    $row->formName,
                    $row->sourceUrl ?? '',
                    $row->ip ?? '',
                    $row->userAgent ?? '',
                ];
                foreach ($fieldKeys as $key) {
                    $value = $row->values[$key] ?? '';
                    $line[] = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
                }
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'form-submissions.csv'),
        );
        return $response;
    }

    /** @return array{form_key?: string, status?: string} */
    private function filterFrom(Request $request): array
    {
        $filter = [];
        $formKey = $request->query->get('form_key');
        if (is_string($formKey) && $formKey !== '') {
            $filter['form_key'] = $formKey;
        }
        $status = $request->query->get('status');
        if (is_string($status) && ($status === 'unread' || $status === 'read')) {
            $filter['status'] = $status;
        }
        return $filter;
    }
}
