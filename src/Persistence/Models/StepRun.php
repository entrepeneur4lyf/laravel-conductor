<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $pipeline_run_id
 * @property string $step_definition_id
 * @property string $status
 * @property int $attempt
 * @property int|null $batch_index
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $error
 * @property string|null $prompt_override
 * @property array<string, mixed>|null $supervisor_decision
 * @property string|null $supervisor_feedback
 * @property Carbon|null $completed_at
 */
final class StepRun extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'batch_index' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'supervisor_decision' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PipelineRun, $this>
     */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }
}
