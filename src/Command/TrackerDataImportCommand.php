<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TrackerImportService;

class TrackerDataImportCommand extends Command
{
    protected static $defaultName = 'tracker:import-data';

    public function __construct(
        private readonly TrackerImportService $trackerImportService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->toOutput('started');

        $this->trackerImportService->runImport();

        $this->toOutputWithStats('finished');

        return 0;
    }
}
