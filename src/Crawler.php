<?php

namespace Spatie\Crawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Spatie\Crawler\Exceptions\InvalidBaseUrl;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Crawler
{
    /**
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * @var \Spatie\Crawler\Url;
     */
    protected $parentUrl;

    /**
     * @var \Spatie\Crawler\Url;
     */
    protected $baseUrl;

    /**
     * @var \Illuminate\Support\Collection
     */
    protected $crawledUrls;

    /**
     * @var \Spatie\Crawler\CrawlObserver
     */
    protected $crawlObserver;

    /**
     * @var \Spatie\Crawler\CrawlProfile
     */
    protected $crawlProfile;

    /**
     * @return static
     */
    public static function create()
    {
        $client = new Client([
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::COOKIES => true,
        ]);

        return new static($client);
    }

    /**
     * @param \GuzzleHttp\Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;

        $this->crawlProfile = new CrawlAllUrls();

        $this->crawledUrls = collect();

        $this->parentUrl = '';
    }

    /**
     * Set the crawl observer.
     *
     * @param \Spatie\Crawler\CrawlObserver $crawlObserver
     *
     * @return $this
     */
    public function setCrawlObserver(CrawlObserver $crawlObserver)
    {
        $this->crawlObserver = $crawlObserver;

        return $this;
    }

    /**
     * Set the crawl profile.
     *
     * @param \Spatie\Crawler\CrawlProfile $crawlProfile
     *
     * @return $this
     */
    public function setCrawlProfile(CrawlProfile $crawlProfile)
    {
        $this->crawlProfile = $crawlProfile;

        return $this;
    }

    /**
     * Start the crawling process.
     *
     * @param \Spatie\Crawler\Url|string $baseUrl
     *
     * @return array|null
     *
     * @throws \Spatie\Crawler\Exceptions\InvalidBaseUrl
     */
    public function startCrawling($baseUrl)
    {
        if (!$baseUrl instanceof Url) {
            $baseUrl = Url::create($baseUrl);
        }

        if ($baseUrl->isRelative()) {
            throw new InvalidBaseUrl();
        }

        $this->baseUrl = $baseUrl;

        $this->crawlUrl($baseUrl, $this->parentUrl);

        return $this->crawlObserver->finishedCrawling();
    }

    /**
     * Crawl the given url and set the parent url.
     *
     * @param \Spatie\Crawler\Url        $url
     * @param \Spatie\Crawler\Url|string $parentUrl
     */
    protected function crawlUrl(Url $url, $parentUrl)
    {
        if (!$this->crawlProfile->shouldCrawl($url)) {
            return;
        }

        if ($this->hasAlreadyCrawled($url)) {
            return;
        }

        $this->crawlObserver->willCrawl($url);

        try {
            $response = $this->client->request('GET', (string) $url);
        } catch (RequestException $exception) {
            $response = $exception->getResponse();
        }

        $this->crawlObserver->hasBeenCrawled($url, $response, $parentUrl);

        $this->crawledUrls->push($url);

        if (!$response) {
            return;
        }

        if ($url->host === $this->baseUrl->host) {
            $this->crawlAllLinks($response->getBody()->getContents(), $url);
        }
    }

    /**
     * Crawl all links in the given html.
     *
     * @param string $html
     * @param string $parentUrl
     */
    protected function crawlAllLinks($html, $parentUrl)
    {
        $allLinks = $this->getAllLinks($html, $parentUrl);

        collect($allLinks)
            ->filter(function ($urls) {
                return !$urls['url']->isEmailUrl();
            })
            ->map(function ($urls) {
                return ['parent' => $this->normalizeUrl($urls['parent']), 'url' => $this->normalizeUrl($urls['url'])];
            })
            ->filter(function ($urls) {
                return $this->crawlProfile->shouldCrawl($urls['url']);
            })
            ->each(function ($urls) {
                $this->crawlUrl($urls['url'], $urls['parent']);
            });
    }

    /**
     * Get all links in the given html and pair them with their parent.
     *
     * @param string $html
     * @param string $parentUrl
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getAllLinks($html, $parentUrl)
    {
        $this->parentUrl = $parentUrl;
        $domCrawler = new DomCrawler($html);

        return collect($domCrawler->filterXpath('//a')
            ->extract(['href']))
            ->map(function ($url) {
                return ['parent' => Url::create($this->parentUrl), 'url' => Url::create($url)];
            });
    }

    /**
     * Determine if the crawled has already crawled the given url.
     *
     * @param \Spatie\Crawler\Url $url
     *
     * @return bool
     */
    protected function hasAlreadyCrawled(Url $url)
    {
        foreach ($this->crawledUrls as $crawledUrl) {
            if ((string) $crawledUrl === (string) $url) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize the given url.
     *
     * @param \Spatie\Crawler\Url $url
     *
     * @return $this
     */
    protected function normalizeUrl(Url $url)
    {
        if ($url->isRelative()) {

            $url->setScheme($this->baseUrl->scheme)
                ->setHost($this->baseUrl->host)
                ->setPort($this->baseUrl->port)
                ->setQuery($this->baseUrl->query);
        }

        if ($url->isProtocolIndependent()) {
            $url->setScheme($this->baseUrl->scheme);
        }

        return $url->removeFragment();
    }
}
