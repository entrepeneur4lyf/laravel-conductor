<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Http\Controllers;

use Entrepeneur4lyf\LaravelConductor\Contracts\WorkflowStateStore;
use Entrepeneur4lyf\LaravelConductor\Data\StepExecutionStateData;
use Entrepeneur4lyf\LaravelConductor\Data\StepOutputData;
use Entrepeneur4lyf\LaravelConductor\Data\WorkflowRunStateData;
use Entrepeneur4lyf\LaravelConductor\Engine\RunProcessor;
use Entrepeneur4lyf\LaravelConductor\Engine\Supervisor;
use Entrepeneur4lyf\LaravelConductor\Engine\WorkflowEngine;
use Entrepeneur4lyf\LaravelConductor\Events\StepRetrying;
use Entrepeneur4lyf\LaravelConductor\Events\WorkflowCancelled;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\CancelWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\ResumeWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\RetryWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Http\Requests\StartWorkflowRequest;
use Entrepeneur4lyf\LaravelConductor\Support\Timeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RunProcessor $processor,
        private readonly WorkflowStateStore $stateStore,
        private readonly Supervisor $supervisor,
    ) {}

    public function start(StartWorkflowRequest $request): JsonResponse
    {
        $run = $this->engine->start(
            $request->string('workflow')->toString(),
            $request->input('input', []),
        );

        return response()->json(['data' => $run->toArray()], 201);
    }

    public function continueRun(string $runId): JsonResponse
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        $decision = $this->processor->continueRun($runId);
        $fresh = $this->stateStore->get($runId) ?? $run;

        return response()->json([
            'data' => $fresh->toArray(),
            'decision' => $decision->toArray(),
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

    public function resume(ResumeWorkflowRequest $request, string $runId): JsonResponse
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        if ($run->status !== 'waiting' || $run->wait === null || $run->current_step_id === null) {
            return response()->json(['message' => 'Run is not waiting.'], 409);
        }

        if ($request->string('resume_token')->toString() !== $run->wait->resume_token) {
            return response()->json(['message' => 'Invalid resume token.'], 422);
        }

        $step = $this->latestStep($run, $run->current_step_id);

        if ($step === null) {
            return response()->json(['message' => 'Current step not found.'], 404);
        }

        $updatedRun = $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'running',
                'wait' => null,
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'workflow_resumed',
                        'Workflow resumed from a waiting state.',
                        ['step_id' => $run->current_step_id],
                    ),
                ),
                'steps' => $this->replaceLatestStep(
                    $run,
                    $run->current_step_id,
                    StepExecutionStateData::from([
                        ...$step->toArray(),
                        'status' => 'completed',
                        'output' => StepOutputData::from([
                            'step_id' => $run->current_step_id,
                            'run_id' => $run->id,
                            'status' => 'completed',
                            'payload' => $request->input('payload', []),
                        ])->toArray(),
                        'supervisor_decision' => null,
                        'supervisor_feedback' => null,
                        'completed_at' => now('UTC')->toIso8601String(),
                    ])->toArray(),
                ),
            ]),
            $run->revision,
        );

        $decision = $this->supervisor->evaluate($updatedRun->id, $updatedRun->current_step_id ?? $run->current_step_id);
        $fresh = $this->stateStore->get($run->id) ?? $updatedRun;

        return response()->json([
            'data' => $fresh->toArray(),
            'decision' => $decision->toArray(),
        ]);
    }

    public function retry(RetryWorkflowRequest $request, string $runId): JsonResponse
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        $expectedRevision = $request->integer('revision');

        if ($expectedRevision !== $run->revision) {
            return response()->json(['message' => 'Run revision mismatch.'], 409);
        }

        if ($run->status !== 'failed' || $run->current_step_id === null) {
            return response()->json(['message' => 'Run is not eligible for retry.'], 422);
        }

        $step = $this->latestStep($run, $run->current_step_id);

        if ($step === null) {
            return response()->json(['message' => 'Current step not found.'], 404);
        }

        $steps = array_map(
            static fn (StepExecutionStateData $existing): array => $existing->toArray(),
            $run->steps,
        );
        $steps[] = StepExecutionStateData::from([
            'step_definition_id' => $run->current_step_id,
            'status' => 'pending',
            'attempt' => $step->attempt + 1,
        ])->toArray();

        $updatedRun = $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'running',
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'step_retried',
                        'Manual retry requested.',
                        ['step_id' => $run->current_step_id, 'attempt' => $step->attempt + 1],
                    ),
                ),
                'steps' => $steps,
            ]),
            $run->revision,
        );

        event(new StepRetrying($updatedRun, $run->current_step_id, 'Manual retry requested.'));

        return response()->json(['data' => $updatedRun->toArray()]);
    }

    public function cancel(CancelWorkflowRequest $request, string $runId): JsonResponse
    {
        $run = $this->stateStore->get($runId);

        if ($run === null) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        $expectedRevision = $request->integer('revision');

        if ($expectedRevision !== $run->revision) {
            return response()->json(['message' => 'Run revision mismatch.'], 409);
        }

        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json(['message' => 'Run is not eligible for cancellation.'], 422);
        }

        $updatedRun = $this->stateStore->save(
            WorkflowRunStateData::from([
                ...$run->toArray(),
                'revision' => $run->revision + 1,
                'status' => 'cancelled',
                'current_step_id' => null,
                'wait' => null,
                'timeline' => array_map(
                    static fn ($entry) => $entry->toArray(),
                    Timeline::append(
                        $run->timeline,
                        'workflow_cancelled',
                        'Manual cancellation requested.',
                    ),
                ),
            ]),
            $run->revision,
        );

        event(new WorkflowCancelled($updatedRun, 'Manual cancellation requested.'));

        return response()->json(['data' => $updatedRun->toArray()]);
    }

    private function latestStep(WorkflowRunStateData $run, string $stepId): ?StepExecutionStateData
    {
        $steps = array_values(array_filter(
            $run->steps,
            static fn (StepExecutionStateData $step): bool => $step->step_definition_id === $stepId,
        ));

        if ($steps === []) {
            return null;
        }

        return $steps[array_key_last($steps)];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function replaceLatestStep(
        WorkflowRunStateData $run,
        string $stepId,
        array $replacement,
    ): array {
        $steps = array_map(
            static fn (StepExecutionStateData $step): array => $step->toArray(),
            $run->steps,
        );

        for ($index = count($steps) - 1; $index >= 0; $index--) {
            if (($steps[$index]['step_definition_id'] ?? null) === $stepId) {
                $steps[$index] = $replacement;

                return $steps;
            }
        }

        return $steps;
    }
}
