<?php

use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

const MON_URL = 'https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon';

/**
 * Mirrors the real MON markup: each card links to a detail page, often via
 * several anchors (image + title + "read more"); the listing link itself has no
 * slug and must be ignored.
 */
function fakeMonHtml(): string
{
    return <<<'HTML'
    <html><body>
      <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon">Конкурси</a>
      <div class="card">
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/first-konkurs"></a>
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/first-konkurs">Конкурс за учебници во основно образование</a>
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/first-konkurs">Прочитај</a>
      </div>
      <div class="card">
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/second-konkurs">Конкурс за студентски домови</a>
      </div>
      <div class="card">
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/third-konkurs">Конкурс за гимназиско образование</a>
      </div>
      <div class="card">
        <a href="https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/fourth-konkurs">Четврти конкурс</a>
      </div>
    </body></html>
    HTML;
}

describe('news:scrape command', function (): void {
    it('scrapes and upserts konkursi in page order with correct titles', function (): void {
        Http::fake([MON_URL => Http::response(fakeMonHtml())]);

        $this->artisan('news:scrape')->assertSuccessful();

        expect(NewsArticle::count())->toBe(4);

        $first = NewsArticle::where('position', 0)->first();
        expect($first->title)->toBe('Конкурс за учебници во основно образование')
            ->and($first->url)->toBe('https://mon.gov.mk/mk-MK/konkursi-i-stipendii/konkursi-mon/first-konkurs')
            ->and($first->source)->toBe('mon')
            ->and($first->published_at)->not->toBeNull();

        // The bare listing link (no slug) is ignored; positions are 0..3.
        expect(NewsArticle::orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
    });

    it('is idempotent and preserves published_at across re-scrapes', function (): void {
        Http::fake([MON_URL => Http::response(fakeMonHtml())]);

        $this->artisan('news:scrape')->assertSuccessful();
        $firstSeen = NewsArticle::where('position', 0)->first()->published_at;

        $this->travel(1)->hours();
        $this->artisan('news:scrape')->assertSuccessful();

        expect(NewsArticle::count())->toBe(4)
            ->and(NewsArticle::where('position', 0)->first()->published_at->timestamp)
            ->toBe($firstSeen->timestamp); // stamped once, on first sight
    });

    it('fails cleanly when the source is unreachable', function (): void {
        Http::fake([MON_URL => Http::response('', 503)]);

        $this->artisan('news:scrape')->assertFailed();

        expect(NewsArticle::count())->toBe(0);
    });
});

describe('GET /api/v1/news', function (): void {
    it('returns the latest 3 articles ordered by position', function (): void {
        foreach (['A' => 2, 'B' => 0, 'C' => 3, 'D' => 1] as $title => $position) {
            NewsArticle::create([
                'source' => 'mon',
                'title' => $title,
                'url' => "https://mon.gov.mk/x/{$title}",
                'position' => $position,
                'published_at' => now(),
            ]);
        }

        actingAs(User::factory()->create());

        $response = getJson('/api/v1/news')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => [['id', 'title', 'url', 'source', 'published_at']]]);

        // position order B(0), D(1), A(2) — the newest three.
        expect($response->json('data.*.title'))->toBe(['B', 'D', 'A']);
    });

    it('requires authentication', function (): void {
        getJson('/api/v1/news')->assertUnauthorized();
    });
});
