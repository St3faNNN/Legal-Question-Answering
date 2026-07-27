<?php

namespace App\Console\Commands;

use App\Models\NewsArticle;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('news:scrape {--limit=12 : Maximum number of articles to store}')]
#[Description('Scrape the latest konkursi from mon.gov.mk and upsert them into news_articles')]
class ScrapeMonNews extends Command
{
    private const SOURCE = 'mon';

    private const PAGE_URL = 'https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon';

    /** Detail pages live one path segment below the listing. */
    private const DETAIL_PREFIX = self::PAGE_URL.'/';

    public function handle(): int
    {
        $response = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0'])
            ->get(self::PAGE_URL);

        if ($response->failed()) {
            $this->error("Failed to fetch MON page: HTTP {$response->status()}");

            return self::FAILURE;
        }

        $articles = $this->parse($response->body(), (int) $this->option('limit'));

        if ($articles === []) {
            $this->warn('No articles found — the MON page structure may have changed.');

            return self::FAILURE;
        }

        foreach ($articles as $article) {
            $record = NewsArticle::firstOrNew(['url' => $article['url']]);
            $isNew = ! $record->exists;

            $record->fill([
                'source' => self::SOURCE,
                'title' => $article['title'],
                'position' => $article['position'],
            ]);

            // MON exposes no real publish date, so stamp the first time we see
            // the article. Set once (also backfills older rows), then preserved.
            if ($record->published_at === null) {
                $record->published_at = now();
            }

            $record->save();

            if ($isNew) {
                // OUT OF SCOPE (live updates): a newly-inserted row is where an
                // `ArticleCreated` event would be dispatched, with a listener
                // broadcasting to the dashboard. Not built — flagged per the brief.
            }
        }

        $this->info(count($articles).' article(s) upserted from '.self::SOURCE.'.');

        return self::SUCCESS;
    }

    /**
     * Extract the konkurs cards from the listing HTML: each distinct detail-page
     * link, in page order (index 0 = latest), titled by the longest anchor text
     * pointing at it.
     *
     * @return list<array{title: string, url: string, position: int}>
     */
    public function parse(string $html, int $limit = 12): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // The XML encoding hint makes DOMDocument decode the Cyrillic as UTF-8.
        $dom->loadHTML('<?xml encoding="UTF-8" ?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $anchors = $xpath->query('//a[contains(@href, "/konkursi-i-stipendii/konkursi-mon/")]');

        /** @var array<string, string> $titles url => best (longest) title text */
        $titles = [];
        /** @var list<string> $order urls in first-seen order */
        $order = [];

        foreach ($anchors ?: [] as $anchor) {
            if (! $anchor instanceof DOMElement) {
                continue;
            }

            $url = $anchor->getAttribute('href');

            // Detail pages only (a non-empty slug), not the listing link itself.
            if (! str_starts_with($url, self::DETAIL_PREFIX) || $url === self::DETAIL_PREFIX) {
                continue;
            }

            if (! isset($titles[$url])) {
                $titles[$url] = '';
                $order[] = $url;
            }

            $text = trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent));
            if (mb_strlen($text) > mb_strlen($titles[$url])) {
                $titles[$url] = $text;
            }
        }

        $articles = [];
        foreach ($order as $url) {
            if ($titles[$url] === '') {
                continue;
            }

            $articles[] = [
                'title' => $titles[$url],
                'url' => $url,
                'position' => count($articles),
            ];

            if (count($articles) >= $limit) {
                break;
            }
        }

        return $articles;
    }
}
