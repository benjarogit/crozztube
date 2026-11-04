<?php

namespace App\Http\Controllers;

use App\Support\SeoSettings;
use App\Support\SitemapData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SitemapController extends Controller
{
    public function index(Request $request, SitemapData $sitemaps): Response
    {
        $settings = SeoSettings::settings();
        abort_unless(data_get($settings, 'indexing.enabled') && data_get($settings, 'sitemap.enabled'), 404);

        $rows = [];
        $latest = SeoSettings::updatedAt();
        foreach ($sitemaps->summaries($settings) as $summary) {
            $modified = $this->date($summary['last_modified'] ?? null);
            if ($modified?->greaterThan($latest)) $latest = $modified;

            for ($page = 1; $page <= $summary['files']; $page++) {
                $path = $summary['section'] === 'pages'
                    ? '/sitemaps/pages.xml'
                    : '/sitemaps/'.$summary['section'].'-'.$page.'.xml';
                $rows[] = '<sitemap><loc>'.$this->xml($this->absoluteUrl($path, $request, $settings)).'</loc>'
                    .($modified ? '<lastmod>'.$this->xml($modified->toAtomString()).'</lastmod>' : '')
                    .'</sitemap>';
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>'
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $rows).'</sitemapindex>';

        return $this->cacheableResponse($request, $xml, 'application/xml; charset=UTF-8', $latest, true);
    }

    public function pages(Request $request, SitemapData $sitemaps): Response
    {
        return $this->urlSet($request, $sitemaps, 'pages', 1);
    }

    public function section(Request $request, SitemapData $sitemaps, string $section, int $page): Response
    {
        abort_unless(in_array($section, array_diff(SeoSettings::SECTIONS, ['pages']), true) && $page > 0, 404);

        return $this->urlSet($request, $sitemaps, $section, $page);
    }

    public function robots(Request $request): Response
    {
        $settings = SeoSettings::settings();
        $lines = ['User-agent: *', 'Allow: /'];

        // Public HTML remains crawlable so its robots meta tag can enforce noindex.
        foreach (['/admin/', '/api/', '/install.php'] as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        foreach (preg_split('/\R/u', (string) data_get($settings, 'robots.customDisallow', '')) ?: [] as $path) {
            if ($path !== '') $lines[] = 'Disallow: '.$path;
        }
        if (data_get($settings, 'indexing.enabled') && data_get($settings, 'sitemap.enabled')) {
            $lines[] = '';
            $lines[] = 'Sitemap: '.$this->absoluteUrl('/sitemap.xml', $request, $settings);
        }

        return $this->cacheableResponse(
            $request,
            implode("\n", array_values(array_unique($lines)))."\n",
            'text/plain; charset=UTF-8',
            SeoSettings::updatedAt(),
        );
    }

    public function stylesheet(Request $request): Response
    {
        $xsl = <<<'XSL'
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9">
<xsl:output method="html" encoding="UTF-8"/>
<xsl:template match="/">
<html><head><title>XML Sitemap</title><meta name="viewport" content="width=device-width, initial-scale=1"/><style>body{font-family:system-ui,sans-serif;margin:0;color:#172033;background:#f6f7fb}main{max-width:1100px;margin:48px auto;padding:0 24px}h1{font-size:28px}p{color:#657186}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #dfe5ee}th,td{padding:12px 16px;border-bottom:1px solid #e8ecf2;text-align:left;word-break:break-all}th{font-size:13px;background:#f9fafc}a{color:#1769d2}</style></head><body><main><h1>XML Sitemap</h1><p>This sitemap is generated from currently indexable website content.</p><table><thead><tr><th>URL</th><th>Last modified</th></tr></thead><tbody><xsl:for-each select="s:sitemapindex/s:sitemap | s:urlset/s:url"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="s:lastmod"/></td></tr></xsl:for-each></tbody></table></main></body></html>
</xsl:template>
</xsl:stylesheet>
XSL;

        return $this->cacheableResponse($request, $xsl, 'application/xslt+xml; charset=UTF-8', SeoSettings::updatedAt());
    }

    private function urlSet(Request $request, SitemapData $sitemaps, string $section, int $page): Response
    {
        $settings = SeoSettings::settings();
        abort_unless($sitemaps->enabled($settings, $section), 404);

        $entries = $sitemaps->entries($section, $page, $settings);
        abort_unless($entries !== [], 404);

        $frequency = data_get($settings, 'sitemap.changeFrequency.'.$section, 'weekly');
        $priority = data_get($settings, 'sitemap.priority.'.$section, 0.5);
        $rows = [];
        $latest = SeoSettings::updatedAt();
        foreach ($entries as $entry) {
            $modified = $this->date($entry['last_modified'] ?? null);
            if ($modified && (! $latest || $modified->greaterThan($latest))) $latest = $modified;
            $row = '<url><loc>'.$this->xml($this->absoluteUrl($entry['path'], $request, $settings)).'</loc>';
            if ($modified) $row .= '<lastmod>'.$this->xml($modified->toAtomString()).'</lastmod>';
            $row .= '<changefreq>'.$this->xml($frequency).'</changefreq>';
            $row .= '<priority>'.number_format((float) ($entry['priority'] ?? $priority), 1, '.', '').'</priority>';

            if (! empty($entry['image'])) {
                $row .= '<image:image><image:loc>'.$this->xml($this->absoluteUrl($entry['image'], $request, $settings)).'</image:loc></image:image>';
            }
            if (! empty($entry['video'])) {
                $row .= $this->videoXml($entry['video'], $request, $settings);
            }
            $rows[] = $row.'</url>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">'
            .implode('', $rows).'</urlset>';

        return $this->cacheableResponse($request, $xml, 'application/xml; charset=UTF-8', $latest ?: SeoSettings::updatedAt(), true);
    }

    private function videoXml(array $video, Request $request, array $settings): string
    {
        $title = Str::limit($this->plainText($video['title'] ?? '', 100), 100, '');
        $description = $this->plainText($video['description'] ?? '', 2048) ?: $title;
        $xml = '<video:video>'
            .'<video:thumbnail_loc>'.$this->xml($this->absoluteUrl($video['thumbnail'], $request, $settings)).'</video:thumbnail_loc>'
            .'<video:title>'.$this->xml($title).'</video:title>'
            .'<video:description>'.$this->xml($description).'</video:description>'
            .'<video:content_loc>'.$this->xml($this->absoluteUrl($video['content'], $request, $settings)).'</video:content_loc>';

        $duration = (int) ($video['duration'] ?? 0);
        if ($duration > 0 && $duration <= 28800) $xml .= '<video:duration>'.$duration.'</video:duration>';
        if ($published = $this->date($video['published_at'] ?? null)) $xml .= '<video:publication_date>'.$this->xml($published->toAtomString()).'</video:publication_date>';
        if ((int) ($video['views'] ?? 0) > 0) $xml .= '<video:view_count>'.(int) $video['views'].'</video:view_count>';
        foreach (array_slice(array_filter((array) ($video['tags'] ?? []), 'is_string'), 0, 32) as $tag) {
            $tag = $this->plainText($tag, 256);
            if ($tag !== '') $xml .= '<video:tag>'.$this->xml($tag).'</video:tag>';
        }
        if (! empty($video['uploader'])) {
            $info = ! empty($video['uploader_path']) ? ' info="'.$this->xml($this->absoluteUrl($video['uploader_path'], $request, $settings)).'"' : '';
            $xml .= '<video:uploader'.$info.'>'.$this->xml($this->plainText($video['uploader'], 255)).'</video:uploader>';
        }

        return $xml.'<video:family_friendly>yes</video:family_friendly></video:video>';
    }

    private function absoluteUrl(string $value, Request $request, array $settings): string
    {
        if (str_starts_with($value, '//')) return $request->getScheme().':'.$value;
        if (preg_match('#^https?://#i', $value)) return $value;

        $base = rtrim((string) data_get($settings, 'canonicalBaseUrl'), '/');
        if ($base === '') $base = $request->getSchemeAndHttpHost();

        return $base.'/'.ltrim($value, '/');
    }

    private function plainText(mixed $value, int $limit): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text, $limit, '');
    }

    private function xml(mixed $value): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function date(mixed $value): ?Carbon
    {
        if (! $value) return null;

        try {
            return $value instanceof Carbon ? $value : Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheableResponse(Request $request, string $body, string $contentType, Carbon $lastModified, bool $profileSensitive = false): Response
    {
        $response = response($body, 200, [
            'Cache-Control' => $profileSensitive ? 'private, no-store' : 'public, max-age=300, must-revalidate',
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if ($profileSensitive) {
            return $response;
        }

        $response->setEtag(hash('sha256', $body));
        $response->setLastModified($lastModified);
        $response->isNotModified($request);

        return $response;
    }
}
