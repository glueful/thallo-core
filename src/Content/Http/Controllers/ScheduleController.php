<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Enums\ScheduleAction;
use Thallo\Core\Content\Http\DTOs\ScheduleData;
use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ScheduleRepository;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Support\ActorHelper;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ScheduleController
{
    public function __construct(
        private readonly ScheduleRepository $schedules,
        private readonly EntryRepository $entries,
        private readonly ContentLocaleService $locales,
    ) {
    }

    #[ApiOperation(summary: 'Schedule a publish/unpublish', tags: ['Thallo Admin'])]
    #[ApiResponse(201, description: 'Schedule created or rescheduled.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Entry not found or deleted.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid schedule payload.')]
    public function store(ScheduleData $input, Request $request, string $uuid, string $locale): Response
    {
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return Response::error('Entry not found.', Response::HTTP_NOT_FOUND);
        }

        $localeErrors = $this->locales->validate($locale);
        if ($localeErrors !== []) {
            return Response::validation($localeErrors);
        }

        $action = ScheduleAction::tryFrom($input->action);
        if ($action === null) {
            return Response::validation(['action' => 'must be one of: publish, unpublish']);
        }

        try {
            $runAt = $this->schedules->normalizeRunAt($input->run_at);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['run_at' => $e->getMessage()]);
        }

        if (strtotime($runAt) <= time()) {
            return Response::validation(['run_at' => 'must be in the future']);
        }

        $row = $this->schedules->schedule($uuid, $locale, $action, $runAt, $this->actor($request));

        return Response::created(['schedule' => $row], 'Schedule saved.');
    }

    #[ApiOperation(summary: 'List an entry\'s schedules', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'Schedules retrieved.')]
    public function index(Request $request, string $uuid): Response
    {
        return Response::success(['schedules' => $this->schedules->forEntry($uuid)], 'Schedules retrieved.');
    }

    #[ApiOperation(summary: 'Cancel a pending schedule', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'Schedule canceled.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Schedule is not pending.')]
    public function destroy(Request $request, string $uuid, string $scheduleUuid): Response
    {
        if (!$this->schedules->cancel($uuid, $scheduleUuid, $this->actor($request))) {
            return Response::error('Only a pending schedule can be canceled.', Response::HTTP_CONFLICT);
        }

        return Response::success([], 'Schedule canceled.');
    }

    private function actor(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }
}
