<?php

$EM_CONF[$_EXTKEY] = [
	'title' => 'Scheduler tasks managed in YAML files',
	'description' => 'Scheduler tasks can be managed in Yaml files along with the project',
	'category' => 'system',
	'dependencies' => 'cms',
	'author' => 'Ernesto Baschny',
	'author_email' => 'eb@cron.eu',
	'author_company' => 'cron IT GmbH',
	'version' => '1.0.0',
	'constraints' => [
		'depends' => [
			'typo3' => '10.4.0-11.5.99',
		],
		'conflicts' => [
		],
		'suggests' => [
		],
	],
	'suggests' => [
	],
];
