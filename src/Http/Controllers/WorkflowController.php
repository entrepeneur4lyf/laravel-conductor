<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Http\Controllers;

use Entrepeneur4lyf\LaravelConductor\Conductor;
use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Engine\WorkflowEngine;
use Entrepeneur4lyf\LaravelConductor\Exceptions\CurrentStepNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\InvalidResumeTokenException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunLockedException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotCancellableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotFoundException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotRetryableException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunNotWaitingException;
use Entrepeneur4lyf\LaravelConductor\Exceptions\RunRevisionMismatchException;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\CancelWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\ResumeWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\RetryWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\StartWorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowStateStore $stateStore,
    ) {}

    public function start(StartWorkflowRequest $request): JsonResponse
    {
        $run = $this->engine->start(
            $request->string('workflow')->toString(),
            $request->input('input', []),
        );

        return response()->json(['data' => $run->toArray()], 201);
    }

    public function continueRun(string $runId, Conductor $conductor): JsonResponse
    {
        if ($this->stateStore->get($runId) === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        try {
            $result = $conductor->continueRun($runId);
        } catch (RunNotFoundException) {
            // Narrow race: the run vanished between the pre-lock check above
            // and the inner reload inside the lock.
            return response()->json(['message' => 'Workflow run not found.'], 404);
        } catch (RunRevisionMismatchException) {
            return response()->json([
                'message' => 'Run state advanced while your request was processing. Reload and retry.',
            ], 409);
        } catch (RunLockedException) {
            return response()->json(['message' => 'Run is currently locked by another request.'], 423);
        }

        return response()->json([
            'data' => $result->run->toArray(),
            'decision' => $result->decision->toArray(),
        ]);
    }

    public function show(string $runId): JsonResponse
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        return response()->json(['data' => $run->toArray()]);
    }

    public function resume(ResumeWorkflowRequest $request, string $runId, Conductor $conductor): JsonResponse
    {
        try {
            $result = $conductor->resumeRun(
                $runId,
                $request->string('resume_token')->toString(),
                $request->input('payload', []),
            );
        } catch (RunNotFoundException) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        } catch (CurrentStepNotFoundException) {
            return response()->json(['message' => 'Current step not found.'], 404);
        } catch (RunNotWaitingException) {
            return response()->json(['message' => 'Run is not waiting.'], 409);
        } catch (InvalidResumeTokenException) {
            return response()->json(['message' => 'Invalid resume token.'], 422);
        } catch (RunLockedException) {
            return response()->json(['message' => 'Run is currently locked by another request.'], 423);
        }

        return response()->json([
            'data' => $result->run->toArray(),
            'decision' => $result->decision->toArray(),
        ]);
    }

    public function retry(RetryWorkflowRequest $request, string $runId, Conductor $conductor): JsonResponse
    {
        try {
            $run = $conductor->retryRun($runId, (int) $request->integer('revision'));
        } catch (RunNotFoundException) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        } catch (CurrentStepNotFoundException) {
            return response()->json(['message' => 'Current step not found.'], 404);
        } catch (RunRevisionMismatchException) {
            return response()->json(['message' => 'Run revision mismatch.'], 409);
        } catch (RunNotRetryableException) {
            return response()->json(['message' => 'Run is not eligible for retry.'], 422);
        } catch (RunLockedException) {
            return response()->json(['message' => 'Run is currently locked by another request.'], 423);
        }

        return response()->json(['data' => $run->toArray()]);
    }

    public function cancel(CancelWorkflowRequest $request, string $runId, Conductor $conductor): JsonResponse
    {
        try {
            $run = $conductor->cancelRun($runId, (int) $request->integer('revision'));
        } catch (RunNotFoundException) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        } catch (RunRevisionMismatchException) {
            return response()->json(['message' => 'Run revision mismatch.'], 409);
        } catch (RunNotCancellableException) {
            return response()->json(['message' => 'Run is not eligible for cancellation.'], 422);
        } catch (RunLockedException) {
            return response()->json(['message' => 'Run is currently locked by another request.'], 423);
        }

        return response()->json(['data' => $run->toArray()]);
    }
}
