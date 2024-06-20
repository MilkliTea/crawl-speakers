<?php

namespace App\Console\Commands;

use DOMXPath;
use Illuminate\Console\Command;

class CrawlerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    private string $crawlerSiteUrl;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->crawlerSiteUrl = env('CRAWLER_SITE_URL');
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $speakers = collect();

        $resultCount = $this->getResultCount();

        do {
            $xpath = $this->createDOM($this->crawlerSiteUrl);
            $nodeList = $xpath->query("//div[@class='space-y-6']");

            foreach ($nodeList as $node) {
                $attributes = $node->attributes;
                $speakerJsonData = $attributes->getNamedItem('speaker')->nodeValue ?? null;

                if (!$speakerJsonData) {
                    continue;
                }

                $speakerData = json_decode($speakerJsonData, true);

                $existSpeaker = $speakers->contains(function ($speaker) use ($speakerData) {
                    return $speaker['id'] === $speakerData['id'];
                });

                if (!$existSpeaker) {
                    $speakers->push($this->prepareSpeakerData($speakerData));
                }
            }
        } while ($speakers->count() < $resultCount);


        file_put_contents('speakers.json', json_encode($speakers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function prepareSpeakerData(array $speaker): array
    {
        return [
            'id' => $speaker['id'],
            'name' => html_entity_decode($speaker['name'], ENT_QUOTES, 'UTF-8'),
            'location' => $speaker['location'],
            'company' => $speaker['company'],
            'job_title' => $speaker['job_title'],
            'twitter' => $speaker['twitter'] ? 'https://x.com/' . $speaker['twitter'] : null,
            'website' => $speaker['website'],
            'youtube' => $speaker['youtube'] ? 'https://youtube.com/@' . $speaker['youtube'] : null,
            'email' => $speaker['email'],
            'talks' => $this->getSpeakerTalks($speaker['username']),
        ];
    }

    private function getSpeakerTalks(string $username): array
    {
        $speakerProfileUrl = $this->crawlerSiteUrl . $username;
        $xpath = $this->createDOM($speakerProfileUrl);

        $talkElements = $xpath->query("//a[@class='flex flex-col rounded-lg hover:shadow-lg overflow-hidden']");

        $talks = [];

        foreach ($talkElements as $talkElement) {
            $talks[] = $this->getTalkDetail($talkElement);
        }

        return $talks;
    }

    private function getTalkDetail($talkElement): array
    {
        $talkUrl = $talkElement->attributes->getNamedItem('href')->nodeValue;

        $talkXPath = $this->createDOM($talkUrl);

        $talkTitleNode = $talkXPath->query("//span[@class='mt-2 block text-3xl font-bold leading-8 tracking-tight text-gray-900 sm:text-4xl']");
        $talkTitle = trim($talkTitleNode->item(0)->nodeValue);

        $talkSliderNode = $talkXPath->query("//a[@class='block']");
        $talkSliderUrl = $talkSliderNode->item(0) ? $talkSliderNode->item(0)->attributes->getNamedItem('href')->nodeValue : null;

        return [
            'title' => html_entity_decode($talkTitle, ENT_QUOTES, 'UTF-8'),
            'talk_url' => $talkUrl,
            'slider_url' => $talkSliderUrl,
        ];
    }

    private function createDOM(string $url): DOMXPath
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->encoding = 'UTF-8';

        libxml_use_internal_errors(true);

        $talkHtml = file_get_contents($url);
        $dom->loadHTML($talkHtml);

        return new DomXPath($dom);
    }

    private function getResultCount(): int
    {
        $xpath = $this->createDOM($this->crawlerSiteUrl);

        $resultCountNode = $xpath->query("//p[@class='text-sm text-gray-700 leading-5 dark:text-gray-400']");

        return (int)$resultCountNode[0]->childNodes[11]->childNodes[0]->nodeValue;
    }
}
