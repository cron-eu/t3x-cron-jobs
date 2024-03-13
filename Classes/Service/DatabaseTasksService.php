<?php

namespace Cron\CronJobs\Service;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Driver\Exception;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Service to manage tasks in the database (add, delete, update, fetch)
 */
class DatabaseTasksService
{

    /**
     * @var int|null
     */
    protected $taskGroupUid = null;

    /**
     * Adds or updates one existing task in the database
     *
     * @return int The uid of the task
     */
    public function addOrUpdateTask(string $identifier, AbstractTask $task, string $sha1, ?int $uid = null): int
    {
        $fields = [
            'nextexecution' => $task->getNextDueExecution(),  # re-calculate next execution in case it has changed
            'disable' => (int)$task->isDisabled(),
            'description' => $task->getDescription(),
            'task_group' => $task->getTaskGroup(),
            'serialized_task_object' => serialize($task),
            'tx_cronjobs_identifier' => $identifier,
            'tx_cronjobs_sha1' => $sha1,
        ];

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task');

        if ($uid === null) {

            // New record
            $fields['crdate'] = $GLOBALS['EXEC_TIME'];
            $connection->insert(
                'tx_scheduler_task',
                $fields,
                ['serialized_task_object' => Connection::PARAM_LOB]
            );
            $uid = (int)$connection->lastInsertId('tx_scheduler_task');

        } else {

            // Update record
            $connection->update(
                'tx_scheduler_task',
                $fields,
                ['uid' => $uid],
                ['serialized_task_object' => Connection::PARAM_LOB]
            );
        }

        // Make sure to save the taskUid in the task itself
        $task->setTaskUid($uid);
        $task->save();

        return $uid;
    }

    /**
     * Deletes a task from the database by its tx_cronjobs_identifier
     */
    public function deleteTask(string $identifier): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_scheduler_task')
            ->delete('tx_scheduler_task', ['tx_cronjobs_identifier' => $identifier]);
    }

    /**
     * Retrieves an array of existing tasks managed by this extension
     *
     * Tasks in the tx_scheduler_task which have the "tx_cronjobs_identifier" field
     *
     * @return array mapping between the identifier and the database row
     * @throws DBALException
     * @throws Exception
     */
    public function getManagedTasks(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_scheduler_task');
        $result = $queryBuilder->select('*')
            ->from('tx_scheduler_task')
            ->where(
                $queryBuilder->expr()->neq('tx_cronjobs_identifier', $queryBuilder->createNamedParameter('', Connection::PARAM_STR))
            )
            ->execute();
        $mapping = [];
        while ($row = $result->fetchAssociative()) {
            $mapping[$row['tx_cronjobs_identifier']] = $row;
        }
        return $mapping;
    }

    /**
     * Retrieves an array of all tasks
     *
     * @return array Rows from the database
     * @throws DBALException
     * @throws Exception
     */
    public function getTasks(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_scheduler_task');
        return $queryBuilder->select('*')
            ->from('tx_scheduler_task')
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * This method returns the task group name for our managed tasks
     *
     * @return int The uid of the tx_scheduler_task_group
     * @throws DBALException
     * @throws Exception
     */
    public function getTaskGroupUid(): int
    {
        if ($this->taskGroupUid !== null) {
            return $this->taskGroupUid;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connection->getQueryBuilderForTable('tx_scheduler_task_group');
        $uid = $queryBuilder
            ->select('uid')
            ->from('tx_scheduler_task_group')
            ->where($queryBuilder->expr()->eq(
                'groupName',
                $queryBuilder->createNamedParameter('cron_jobs', Connection::PARAM_STR)
            )
            )
            ->execute()
            ->fetchOne();

        if (empty($uid)) {
            // Group does not exist yet, create it
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $data['tx_scheduler_task_group']['NEW'] = [
                'pid' => 0,
                'groupName' => 'cron_jobs',
                'description' => '!!! Managed by EXT:cron_jobs, these tasks come from config file ' . SyncTasksService::CONFIG_FILENAME
            ];
            $pseudoAdmin = clone $GLOBALS['BE_USER'];
            $pseudoAdmin->user['admin'] = true;
            $pseudoAdmin->user['uid'] = 1;
            $dataHandler->bypassWorkspaceRestrictions = true;
            $dataHandler->start($data, [], $pseudoAdmin);
            $dataHandler->process_datamap();
            if (!empty($dataHandler->errorLog)) {
                throw new \Exception(join("\n", $dataHandler->errorLog));
            }
            $uid = $dataHandler->substNEWwithIDs['NEW'];
        }

        $this->taskGroupUid = (int)$uid;
        return $this->taskGroupUid;
    }

}
