<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class CommitstripDownloaderCommand extends Command
{
    protected static $defaultName = 'commitstrip-downloader';

    private string $url = 'https://www.commitstrip.com/';

    private int $stripsCount = 0;
    private string $language = 'en';

    private string $location = '';

    private const STRIPS_BY_PAGE = 20;

    protected function configure(): void
    {
        $this
            ->setDescription('Download all Commitstrips comics')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $helper = $this->getHelper('question');
        $languageQuestion = new ChoiceQuestion(
        	'Which language do you want to download ?',
	        ['en', 'fr'],
	        0
        );
        $languageQuestion->setErrorMessage('Chosen language does not exists');
        $this->language = $helper->ask($input, $output, $languageQuestion);

        // Add language in URL
	    $this->url .= $this->language . '/';

	    $directoryCallback = function (string $userInput): array {
		    $inputPath = preg_replace('%(/|^)[^/]*$%', '$1', $userInput);
		    $inputPath = '' === $inputPath ? '.' : $inputPath;
		    $foundFilesAndDirs = @scandir($inputPath) ?: [];

		    return array_map(function ($dirOrFile) use ($inputPath) {
			    return is_dir($inputPath.$dirOrFile) ? $inputPath.$dirOrFile . '/' : null;
		    }, $foundFilesAndDirs);
	    };

	    $locationQuestion = new Question('Please enter the location where you want to download your files : ', './images/');
	    $locationQuestion->setAutocompleterCallback($directoryCallback);
	    $locationQuestion->setValidator(function ($location) {
	    	if (!is_dir($location)) {
			    throw new \Exception('You must select a valid directory');
		    }
	    	if (!is_writable($location)) {
			    throw new \Exception('Location is not writable');
		    }

	    	return $location;
	    });
	    $this->location = $helper->ask($input, $output, $locationQuestion);

	    $output->writeln('Retrieving strips count… ');

	    $lastPageLink = $this->getLastPageLink();
	    $pageCount = preg_replace('/\D/', '', $lastPageLink);
	    $this->stripsCount = (($pageCount - 1) * self::STRIPS_BY_PAGE) + $this->getStripCounts($lastPageLink);

	    $output->writeln(sprintf('%d strips have been found.', $this->stripsCount));
	    $output->writeln('Starting download');

	    $this->downloadStrips($pageCount, $io);

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

		return Command::SUCCESS;
    }

    private function getLastPageLink(): string
    {
    	$client = HttpClient::create();
    	$homePageRequest = $client->request('GET', $this->url);

    	$homePage = new Crawler($homePageRequest->getContent(false));

		return $homePage->filter('.last')->attr('href');
    }

    private function getStripCounts(string $lastPageLink): int
    {
    	$client = HttpClient::create();
    	$stripsRequest = $client->request('GET', $lastPageLink);

    	$stripPage = new Crawler($stripsRequest->getContent(false));

    	return $stripPage->filter('.excerpt')->count();
    }

    private function downloadStrips(int $lastPage, SymfonyStyle $io): void
    {
    	$client = HttpClient::create();

		$io->progressStart($this->stripsCount);

    	for ($i = $lastPage; $i > 0; $i--) {
    		$pageRequest = $client->request('GET', $this->url . 'page/' . (string)$i);
    		$pageCrawler = new Crawler($pageRequest->getContent(false));

    		/** @var Crawler $stripsOnPage */
    		$stripsOnPage = $pageCrawler->filter('.excerpt a');

    		foreach ($stripsOnPage as $strip) {
    			$this->downloadSingleStrip($strip->getAttribute('href'), $io);
				$io->progressAdvance();
		    }
	    }
		$io->progressFinish();
    }

    private function downloadSingleStrip(string $pageLocation, SymfonyStyle $io): void
    {
    	$client = HttpClient::create();

    	if ($this->language === 'fr') {
    		$pageLocation = str_replace('.com/', '.com/fr/', $pageLocation);// . '?setLocale=1';
	    }
    	$page = $client->request('GET', $pageLocation);

    	$pageContent = new Crawler($page->getContent(false));

    	$strip = $pageContent->filter('.entry-content img');

    	if ($strip->count() === 0) {
    		$io->warning(sprintf('No strip found for %s', $pageLocation));
    		return;
	    }

    	$stripLink = $strip->attr('src');

	    if ($this->language === 'fr') {
		    $stripLink = str_replace('/en/', '/fr/', $stripLink);
	    }
		if (!str_starts_with($stripLink, 'http')) {
			$stripLink = 'https:' . $stripLink;
		}
    	$strip = $client->request('GET', $stripLink);
    	$stripName = $this->createStripName($pageLocation, $stripLink);

    	file_put_contents($stripName, $strip->getContent(false));
    }

    private function createStripName(string $url, string $filename): string
    {
    	// substr to remote /{lang}/ at the beginning
    	$path = substr(parse_url($url, PHP_URL_PATH), 4);

    	$cleanedPath = $this->location . str_replace('/', '_', $path);

    	return (substr($cleanedPath, -1) === '_' ? substr($cleanedPath, 0, -1) : $cleanedPath) . '.' . pathinfo($filename, PATHINFO_EXTENSION);;
    }
}
