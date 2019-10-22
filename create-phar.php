<?php
echo 'Starting build';

// Removing phar if already exists
$pharFile = 'commitstrip-downloader.phar';
if (file_exists($pharFile)) {
	unlink($pharFile);
}

$phar = new Phar($pharFile);
$phar->startBuffering();
$defaultStub = $phar->createDefaultStub('index.php');
$phar->buildFromDirectory('.', '/\.php$/');
$phar->setDefaultStub('/index.php', '/index.php');
$stub = "#!/usr/bin/php \n".$defaultStub;
$phar->setStub($stub);
$phar->stopBuffering();
