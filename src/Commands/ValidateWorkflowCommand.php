<?php

declare(strict_types=1);

namespace Entrepeneur4lyf\LaravelConductor\Commands;

use Entrepeneur4lyf\LaravelConductor\Contracts\DefinitionRepository;
use Entrepeneur4lyf\LaravelConductor\Definitions\WorkflowCompiler;
use Illuminate\Console\Command;

final class ValidateWorkflowCommand extends Command
{
    protected $signature = 'conductor:validate {workflow : Workflow name or file path}';

    protected $description = 'Validate a workflow definition and compile its referenced assets.';

    public function handle(DefinitionRepository $definitions, WorkflowCompiler $compiler): int
    {
        try {
            $loaded = $definitions->load($this->argument('workflow'));
            $compiled = $compiler->compile($loaded);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Workflow [%s] is valid.', $compiled->name));
        $this->line(sprintf(
            'Compiled %d step(s) and %d failure handler(s).',
            count($compiled->steps),
            count($compiled->failure_handlers),
        ));

        return self::SUCCESS;
    }
}
