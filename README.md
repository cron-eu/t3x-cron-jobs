Manage TYPO3 scheduled tasks in files
=====================================

Usually you maintain scheduler tasks manually inside a TYPO3 instance in the database directly using
the backend module "Scheduler".

This extension allows to manage your tasks in a YAML configuration file, which is kept in sync
with the database. With this, you can keep your tasks versioned, deployable and also reproducible
(i.e. you can maintain a set of "maintainence tasks" in your wiki for easy copy&paste to new projects).

## Installation

```
composer require cron-eu/cron-jobs
```

Note: This neither replaces the EXT:scheduler, nor does it interfere on the way the regular
scheduler jobs are processed. Tasks created by this extension are still in the database and
appear as regular tasks in the Scheduler backend module.

It will not touch manually added tasks. Tasks managed by cron_jobs will be placed inside a
separate task group called "cron_jobs".

## Usage

You work with it like this:

### YAML configuration

A new YAML configuration file is introduced: `config/scheduler/tasks.yaml`. It looks like this:

```yaml
tasks:
  RefIndex:
    command: 'referenceindex:update'
    cronCmd: '0 3 * * *'
    description: 'Update reference index'

  CachingFrameworkGarbageCollection:
    description: 'Garbage collection for caching framework'
    class: 'TYPO3\CMS\Scheduler\Task\CachingFrameworkGarbageCollectionTask'
    interval: 180
    properties:
        selectedBackends:
        - 'TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend'
        - 'TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend'
        - 'TYPO3\CMS\Core\Cache\Backend\FileBackend'

  brofix-checklinks:
    description: 'Check links for pid=6'
    command: 'brofix:checklinks'
    options:
        start-pages: '6'
        depth: ''
        to: 'mail@example.com'
        dry-run: false
        send-email: '1'
    cronCmd: '30 3 1,15 * *'
    condition: 'applicationContext matches "/Production\\/Live/"'
```

### Sync command controller

Once you have this file you can call:

```
bin/typo3 cronjobs:sync
```

It will create the defined tasks in your database (table `tx_scheduler_tasks`).

You can call this command after that, and it will keep your tasks in sync with this file. So as long
as you keep the same identifiers (in our example, `RefIndex` or `CachingFrameworkGarbageCollection`) you
can change the settings inside the YAML file, and the sync command will take care of updating it in
the database. If nothing changed in your tasks YAML file, nothing will change in your database.

The sync will:

* add new tasks which have not yet added by cron_jobs
* update tasks which have changed definitions (i.e. new cronCmd or options)
* delete tasks which were previously managed by cron_jobs which are no longer part of this file

Important: You should not touch tasks managed by cron_jobs manually in the backend module!

### Export command controller

If you already have tons of tasks not yet managed by cron_jobs in your installation, you can call this:

```
bin/typo3 cronjobs:export
```

This will output a YAML file that you can add manually to your project as a kickstart (you still have
to manually delete the manually created tasks from your database).

## Reference

### Syntax of `config/scheduler/tasks.yaml`

The YAML file `config/scheduler/tasks.yaml` is expected to have a root element called `tasks:`. Below
that is a dictionary of identifiers which will also be stored in the database for later finding the tasks.
Use unique names, for example a shortcut of the name of the task.

Options:

* `class` or `command` (either one of these is required): Either a full valid classname of an
  AbstractTask for the scheduler (`class`), or a `command` for a command controller which is marked
  as `schedulable: true` in the Services.yaml.
* `options` (for `comamnd` only): parameters to pass to the command controller
* `properties` (for `class` only): if the properties to set in the task are public properties, you
  can set them directly with this
* `additionalFields` (for `class` only): fields to pass to the task, same fields that are used by the
  backend module (use the inspector to see their names). This uses the AdditionalFieldProvider of the task
* `cronCmd` or `interval` (either one of these is required): as described in the scheduler backend module
* `description` (optional): will also be shown in the backend module
* `condition` (optional): if you want a task to only be created depending on certain criteria (usually `applicationContext`)
  you can write a condition in the regular "condition syntax" which is also known from the site config YAML.
