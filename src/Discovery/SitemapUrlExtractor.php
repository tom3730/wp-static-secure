<?php

declare(strict_types=1);

namespace WPStaticSecure\Discovery;

final class SitemapUrlExtractor
{
    /** @return list<string> */
    public function extract(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        preg_match_all(
            '~<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?loc\b[^>]*>(.*?)</(?:[A-Za-z_][A-Za-z0-9_.-]*:)?loc\s*>~si',
            $xml,
            $matches
        );

        $urls = [];
        foreach ($matches[1] ?? [] as $rawValue) {
            $value = trim((string) $rawValue);
            if (str_starts_with($value, '<![CDATA[') && str_ends_with($value, ']]>')) {
                $value = substr($value, 9, -3);
            }

            // Standard XML character entities are enough for sitemap <loc> content.
            // We deliberately do not invoke an XML entity resolver here.
            $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($value !== '') {
                $urls[] = $value;
            }
        }

        return $urls;
    }
}
