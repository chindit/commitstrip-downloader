<?php

use App\Command\CommitstripDownloaderCommand;
use Symfony\Component\Console\Application;

require __DIR__ . '/vendor/autoload.php';

$application = new Application('commitstrip-downloader', '1.0.2');
$commitstripCommand = new CommitstripDownloaderCommand();

$application->add($commitstripCommand);

$application->setDefaultCommand($commitstripCommand->getName(), true);

$application->run();
