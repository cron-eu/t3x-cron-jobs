<?php

namespace Cron\CronJobs\Command;

use Cron\CronJobs\Service\SyncTasksService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncCommand extends Command
{

    /**
     * @var SymfonyStyle|null
     */
    protected $io = null;

    /**
     * @var array|null
     */
    protected $conf = null;

    public function injectSyncTasks(SyncTasksService $syncTasksService)
    {
        $this->syncTasksService = $syncTasksService;
    }

    /**
     * Configure the command by defining the name
     */
    protected function configure()
    {
        $this->setDescription('Synchronize scheduled tasks from yaml to database');
    }

    /**
     * Executes the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // For more styles/helpers see: https://symfony.com/doc/current/console/style.html
        $this->io = new SymfonyStyle($input, $output);

        if ($this->io->isVerbose()) {
            $this->io->title($this->getDescription());
        }

        $this->syncTasksService->run($this->io);

        return Command::SUCCESS;
    }

}
