<?php

namespace Cron\CronJobs\Service;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\ExpressionLanguage\Resolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;

/**
 * Synchronizes tasks defined in config/scheduler/tasks.yaml with the database.
 *
 * Loops through all yaml tasks and synchronizes them with what we have in the database.
 * Puts them all in a scheduler tasks group called "cron_jobs" so that they are visually separated from
 * other manually created tasks.
 *
 * This only touches "managed scheduled tasks", which have a tx_cronjobs_identifier property set, it lets
 * every other task (manually created) as is.
 *
 */
class SyncTasksService
{

    public const CONFIG_FILENAME = 'scheduler/tasks.yaml';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var DatabaseTasksService
     */
    protected $databaseTaskService;

    public function __construct(
        DatabaseTasksService $databaseTaskService
    ) {
        $this->databaseTaskService = $databaseTaskService;
    }

    public function run(SymfonyStyle $io)
    {
        $this->io = $io;

        $configFile = Environment::getConfigPath() . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME;
        $yamlLoader = GeneralUtility::makeInstance(YamlFileLoader::class);

        $yamlTasks = $yamlLoader->load($configFile);
        $dbTasks = $this->databaseTaskService->getManagedTasks();

        $this->syncYamlTasksToDb($yamlTasks['tasks'], $dbTasks);

        #var_dump($yamlTasks['tasks']);
    }

    /**
     * Process the synchronization between Yaml and database:
     *
     *   - adds new tasks
     *   - updates already present tasks which have changed specs
     *   - deletes tasks which are no longer present in the yaml
     *
     * @param array $yamlTasks Array of tasks from scheduler/tasks.yaml, section "tasks:"
     * @param array $dbTasks Scheduler tasks from the database, indexed by identifier
     * @return void
     * @throws \Exception
     */
    protected function syncYamlTasksToDb(array $yamlTasks, array $dbTasks)
    {
        $remaining = $dbTasks;
        foreach ($yamlTasks as $identifier => $taskDetails) {
            if (isset($taskDetails['condition'])) {
                if (! $this->evaluateCondition($taskDetails['condition'])) {
                    if ($this->io->isVerbose()) {
                        $this->io->info(sprintf('Skipping task "%s" (condition does not match)', $identifier));
                    }
                    continue;
                }
            }
            unset($remaining[$identifier]);

            $sha1 = sha1(serialize($taskDetails));
            if (isset($dbTasks[$identifier])) {
                // Yaml task is already in the database
                if ($dbTasks[$identifier]['tx_cronjobs_sha1'] !== $sha1) {
                    // Something changed, need to update
                    if ($this->io->isVerbose()) {
                        $this->io->info(sprintf('Updating changed task "%s"', $identifier));
                    }
                    $this->databaseTaskService->addOrUpdateTask($identifier, $this->createTaskFromArray($identifier, $taskDetails), $sha1, $dbTasks[$identifier]['uid']);

                } else {
                    // Unchanged, keep it
                    if ($this->io->isVeryVerbose()) {
                        $this->io->note(sprintf('Task "%s" already present and unchanged', $identifier));
                    }
                }
            } else {
                // Yaml task is not in the database yet, add it
                if ($this->io->isVerbose()) {
                    $this->io->info(sprintf('Adding new task "%s"', $identifier));
                }
                $this->databaseTaskService->addOrUpdateTask($identifier, $this->createTaskFromArray($identifier, $taskDetails), $sha1);
            }
        }

        // Remove other remaining managed tasks, as they were deleted or no longer match the condition
        foreach ($remaining as $identifier => $taskDetails) {
            if ($this->io->isVerbose()) {
                $this->io->info(sprintf('Deleting no longer valid task "%s"', $identifier));
            }
            $this->databaseTaskService->deleteTask($identifier);
        }
    }

    /**
     * Converts a task defined in the YAML file to a Scheduler "AbstractTask" class
     *
     * @throws DBALException
     * @throws Exception
     */
    protected function createTaskFromArray(string $identifier, array $taskDetails): AbstractTask
    {
        if (isset($taskDetails['class'])) {
            // Regular "scheduler task" (subclass of AbstractTask)

            /** @var AbstractTask $task */
            $task = GeneralUtility::makeInstance($taskDetails['class']);
            if (isset($taskDetails['properties'])) {
                // either set the public properties directly...
                foreach ($taskDetails['properties'] as $property => $value) {
                    $task->$property = $value;
                }
            }
            if (isset($taskDetails['additionalFields'])) {
                // ... or use the AdditionalFieldProvider's to set stuff like the backend module does
                if (!empty($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskDetails['class']]['additionalFields'])) {
                    /** @var AdditionalFieldProviderInterface $providerObject */
                    $providerObject = GeneralUtility::makeInstance($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][$taskDetails['class']]['additionalFields']);
                    if ($providerObject instanceof AdditionalFieldProviderInterface) {
                        $providerObject->saveAdditionalFields($taskDetails['additionalFields'] ?? [], $task);
                    }
                }
            }

        } elseif ($taskDetails['command']) {
            // Command controller with "schedulable: true"

            if (!$this->commandExistsAndIsSchedulable($taskDetails['command'])) {
                throw new \Exception(sprintf(sprintf('Cannot create task %s, command "%s" does not exist or is not schedulable', $identifier, $taskDetails['command'])));
            }
            /** @var ExecuteSchedulableCommandTask $task */
            $task = GeneralUtility::makeInstance(ExecuteSchedulableCommandTask::class);
            $task->setCommandIdentifier($taskDetails['command']);
            $task->setOptionValues($taskDetails['options'] ?? []);

        } else {
            throw new \Exception(sprintf(sprintf('Cannot create task %s, missing "class" or "command"', $identifier)));

        }

        $task->registerRecurringExecution($GLOBALS['EXEC_TIME'], $taskDetails['interval'] ?? null, 0, false, $taskDetails['cronCmd'] ?? null);
        $task->setDisabled($taskDetails['disabled'] ?? false);
        $task->setDescription($identifier . (isset($taskDetails['description']) ? ': ' . $taskDetails['description'] : ''));
        $task->setTaskGroup($this->databaseTaskService->getTaskGroupUid());

        return $task;
    }

    /**
     * Evaluable a condition using the TYPO3 expression language evaluator
     */
    protected function evaluateCondition($conditionString): bool
    {
        $expressionLanguageResolver = GeneralUtility::makeInstance(
            Resolver::class,
            'site',
            []
        );
        return $expressionLanguageResolver->evaluate($conditionString);
    }

    /**
     * Given a command string (i.e. "referenceindex:update") checks if it is existing and schedulable
     */
    private function commandExistsAndIsSchedulable(string $command): bool
    {
        $commandRegistry = GeneralUtility::makeInstance(CommandRegistry::class);
        foreach ($commandRegistry->getSchedulableCommands() as $commandIdentifier => $details) {
            if ($command === $commandIdentifier) {
                return true;
            }
        }
        return false;
    }

}
