<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $workflow
 * @property int $workflow_version
 * @property int $revision
 * @property string $status
 * @property string|null $current_step_id
 * @property array<string, mixed> $input
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $wait
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $context
 * @property array<int, array<string, mixed>>|null $timeline
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StepRun> $stepRuns
 */
final class PipelineRun extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow_version' => 'integer',
            'revision' => 'integer',
            'input' => 'array',
            'snapshot' => 'array',
            'wait' => 'array',
            'output' => 'array',
            'context' => 'array',
            'timeline' => 'array',
        ];
    }

    /**
     * @return HasMany<StepRun, $this>
     */
    public function stepRuns(): HasMany
    {
        return $this->hasMany(StepRun::class)
            ->orderBy('attempt')
            ->orderBy('batch_index')
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
