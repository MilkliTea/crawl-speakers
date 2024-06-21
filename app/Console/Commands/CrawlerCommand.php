<?php

namespace App\Console\Commands;

use DOMElement;
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

        $bar = $this->output->createProgressBar($resultCount);

        do {
            $xpath = $this->createDOM($this->crawlerSiteUrl);
            $nodeList = $xpath->query("//div[@class='space-y-6']");

            foreach ($nodeList as $node) {
                $attributes = $node->attributes;
                $speakerJsonData = $attributes->getNamedItem('speaker')->nodeValue ?? null;

                $imageUrl = $node->childNodes[1]->childNodes[1]->attributes->getNamedItem('src')->nodeValue ?? null;
                if (!$speakerJsonData) {
                    continue;
                }

                $speakerData = json_decode($speakerJsonData, true);

                $existSpeaker = $speakers->contains(function ($speaker) use ($speakerData) {
                    return $speaker['id'] === $speakerData['id'];
                });

                if (!$existSpeaker) {
                    $speakers->push($this->prepareSpeakerData($speakerData, $imageUrl));
                    $bar->advance();
                }
            }
        } while ($speakers->count() < $resultCount);

        $bar->finish();
        $this->info(' ');
        $this->info('Konuşmacılar başarıyla çekildi. Dosya oluşturuluyor...');

        file_put_contents('speakers.json', json_encode($speakers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info('Dosya oluşturuldu.');

        return Command::SUCCESS;
    }

    private function prepareSpeakerData(array $speaker, string $imageUrl): array
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
            'image' => $imageUrl,
            'bio' => str_replace("\n", ' ', html_entity_decode($speaker['bio'], ENT_QUOTES, 'UTF-8')),
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

    private function getTalkDetail(DOMElement $talkElement): array
    {
        $talkUrl = $talkElement->attributes->getNamedItem('href')->nodeValue;

        $talkXPath = $this->createDOM($talkUrl);

        $talkTitleNode = $talkXPath->query("//span[@class='mt-2 block text-3xl font-bold leading-8 tracking-tight text-gray-900 sm:text-4xl']");
        $talkTitle = trim($talkTitleNode->item(0)->nodeValue);

        $talkSliderNode = $talkXPath->query("//a[@class='block']")->item(0);
        $talkSliderUrl = $talkSliderNode ? $talkSliderNode->attributes->getNamedItem('href')->nodeValue : null;

        $talkDurationNode = $talkXPath->query("//span[@class='mt-3 block text-sm tracking-tight text-gray-700']")->item(0);
        $talkDuration = $talkDurationNode ? trim($talkDurationNode->childNodes[2]->nodeValue) : null;

        return [
            'title' => html_entity_decode($talkTitle, ENT_QUOTES, 'UTF-8'),
            'duration' => $talkDuration,
            'talk_url' => $talkUrl,
            'slider_url' => $talkSliderUrl,
            'about_this_talk' => $this->getTalkDescription($talkXPath, 'prose prose-sm md:prose-base prose-indigo mx-auto bg-gray-100 text-gray-700 mt-12 px-4 pb-1 pt-5 shadow rounded-lg'),
            'description' => $this->getTalkDescription($talkXPath, 'relative group prose prose-lg prose-indigo mx-auto mt-6 text-gray-500'),
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

    private function getTalkDescription(DOMXPath $talkXPath, string $class): ?string
    {
        $talkDescriptionNode = $talkXPath->query("//div[@class='$class']/div")->item(0);

        if (!$talkDescriptionNode) {
            return null;
        }

        $newDom = new \DOMDocument();
        $newDom->appendChild($newDom->importNode($talkDescriptionNode, true));

        $secondDivContent = $newDom->saveHTML();

        $description = str_replace("\n", ' ', trim(strip_tags($secondDivContent)));

        return html_entity_decode($description, ENT_QUOTES, 'UTF-8');
    }
}
