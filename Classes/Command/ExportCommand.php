<?php

namespace Cron\CronJobs\Command;

use Cron\CronJobs\Service\DatabaseTasksService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;

/**
 * Exports existing scheduler tasks to our YAML syntax
 *
 * Note that this is only output to stdout as a starting point, you still need to create
 * the config/scheduler/tasks.yaml from this by yourself and then also remove the existing
 * manually created tasks from the database yourself.
 */
class ExportCommand extends Command
{

    protected ?SymfonyStyle $io = null;
    protected ?array $conf = null;
    protected ?DatabaseTasksService $databaseTasksService = null;

    public function injectDatabaseTasksService(DatabaseTasksService $databaseTasksService)
    {
        $this->databaseTasksService = $databaseTasksService;
    }

    /**
     * Configure the command by defining the name
     */
    protected function configure()
    {
        $this->setDescription('Exports existing scheduled tasks from database to a yaml syntax');
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

        $yamlTasks = [];
        $tasks = $this->databaseTasksService->getTasks();
        foreach ($tasks as $task) {
            /** @var AbstractTask $schedulerTask */
            $schedulerTask = unserialize($task['serialized_task_object']);
            if (! $schedulerTask instanceof AbstractTask) {
                continue;
            }
            $className = get_class($schedulerTask);

            $yamlTask = [];
            if ($schedulerTask->getDescription()) {
                $yamlTask['description'] = $schedulerTask->getDescription();
            }
            if ($schedulerTask->isDisabled()) {
                $yamlTask['disabled'] = 1;
            }
            if ($className === ExecuteSchedulableCommandTask::class) {
                /** @var ExecuteSchedulableCommandTask $schedulerTask */
                $yamlTask['command'] = $schedulerTask->getCommandIdentifier();
                $identifier = str_replace(':', '-', $schedulerTask->getCommandIdentifier());
                $yamlTask['options'] = $schedulerTask->getOptionValues();

            } else {
                $yamlTask['class'] = $className;
                // Make a shortname of the classname as an identifier
                if (preg_match('/(\w+?)(Task)?$/', $className, $matches)) {
                    $identifier = $matches[1];
                } else {
                    $identifier = $className;
                }

                $reflection = new \ReflectionObject($schedulerTask);
                $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
                foreach ($properties as $property) {
                    $propertyName = $property->getName();
                    $propertyValue = $property->getValue($schedulerTask);
                    $yamlTask['properties'][$propertyName] = $propertyValue;
                }
            }
            if ($schedulerTask->getExecution()->getCronCmd() !== '') {
                $yamlTask['cronCmd'] = $schedulerTask->getExecution()->getCronCmd();
            }
            if ($schedulerTask->getExecution()->getInterval() > 0) {
                $yamlTask['interval'] = $schedulerTask->getExecution()->getInterval();
            }

            if (array_key_exists($identifier, $yamlTasks)) {
                // Make the identifier unique: Append "-integer" until a unique key is found
                $counter = 1;
                while (array_key_exists($identifier . '-' . $counter, $yamlTasks)) {
                    $counter++;
                }
                $identifier = $identifier . '-' . $counter;
            }
            $yamlTasks[$identifier] = $yamlTask;
        }

        echo Yaml::dump($yamlTasks, 4);

        return Command::SUCCESS;
    }

}
