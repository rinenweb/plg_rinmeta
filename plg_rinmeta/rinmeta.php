<?php
/**
 * System Plugin for Joomla! - RinMeta
 *
 * Adds Open Graph and Twitter (X) metadata to article pages and, optionally,
 * to the language-specific homepage. Designed for Joomla sites where the
 * homepage is module-only and content plugin events are not fired.
 *
 * Compatibility: Joomla 4.x, 5.x and 6.x on PHP 7.2+.
 *
 * @author     rinenweb.eu <info@rinenweb.eu>
 * @license    GNU GPL v3 or later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

class PlgSystemRinmeta extends CMSPlugin
{
    /**
     * Add metadata before Joomla renders the <head> section.
     *
     * @return  void
     */
    public function onBeforeCompileHead()
    {
        $app = Factory::getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $doc = $app->getDocument();

        if (!method_exists($doc, 'getType') || $doc->getType() !== 'html') {
            return;
        }

        // Homepage is evaluated first so a single-article/module homepage can
        // get website metadata instead of inheriting article metadata.
        if ((int) $this->params->get('include_homepage', 0) === 1 && $this->isHomePage()) {
            $this->setHomePageMetadata();

            return;
        }

        if ((int) $this->params->get('include_articles', 1) === 1 && $this->isArticlePage()) {
            $this->setArticlePageMetadata();
        }
    }

    /**
     * Detect whether the current request is a com_content article page.
     */
    protected function isArticlePage(): bool
    {
        $input = Factory::getApplication()->getInput();

        return $input->getCmd('option') === 'com_content'
            && $input->getCmd('view') === 'article'
            && $input->getInt('id') > 0;
    }

    /**
     * Detect whether the current menu item is the language-specific homepage.
     */
    protected function isHomePage(): bool
    {
        $app    = Factory::getApplication();
        $menu   = $app->getMenu();
        $active = $menu->getActive();

        if (!$active) {
            return false;
        }

        $language = $app->getLanguage()->getTag();
        $default  = $menu->getDefault($language) ?: $menu->getDefault('*') ?: $menu->getDefault();

        return $default && (int) $active->id === (int) $default->id;
    }

    /**
     * Set metadata for the language-specific homepage.
     */
    protected function setHomePageMetadata(): void
    {
        $app        = Factory::getApplication();
        $doc        = $app->getDocument();
        $active     = $app->getMenu()->getActive();
        $menuParams = $active ? $active->getParams() : null;

        $title = trim((string) $this->params->get('homepage_title', ''));

        if ($title === '' && $menuParams) {
            $title = trim((string) ($menuParams->get('page_title') ?: $active->title));
        }

        if ($title === '') {
            $title = trim((string) ($doc->getTitle() ?: $app->get('sitename')));
        }

        $metadesc = trim((string) $this->params->get('homepage_description', ''));

        if ($metadesc === '' && $menuParams) {
            $metadesc = trim((string) $menuParams->get('menu-meta_description', ''));
        }

        if ($metadesc === '') {
            $metadesc = trim((string) ($doc->getDescription() ?: $app->get('MetaDesc', '')));
        }

        $image = $this->normalizeImageUrl((string) $this->params->get('homepage_image', ''));

        if ($image === '') {
            $image = $this->normalizeImageUrl((string) $this->params->get('default_image', ''));
        }

        $title = $this->cleanText($title);

        $this->setTwitterMetadata($title, $image, $metadesc);
        $this->setOpenGraphMetadata($title, $image, $metadesc, $this->getCurrentCleanUrl(), 'website');
    }

    /**
     * Set metadata for the current article page.
     */
    protected function setArticlePageMetadata(): void
    {
        $article = $this->getCurrentArticle();

        if (!$article) {
            return;
        }

        $text     = trim((string) ($article->introtext ?? '') . ' ' . (string) ($article->fulltext ?? ''));
        $title    = $this->cleanText($this->setMetatitle($article->metadata ?? '', $article->title ?? ''));
        $image    = $this->setImage($article->images ?? '', $text);
        $metadesc = $this->setMetadesc($article->metadesc ?? '', $text);

        $this->setTwitterMetadata($title, $image, $metadesc);
        $this->setOpenGraphMetadata($title, $image, $metadesc, $this->getCurrentCleanUrl(), 'article');

        if ((int) $this->params->get('article_meta', 1) === 1) {
            $this->setArticleObjectMetadata($article);
        }
    }

    /**
     * Load the current article directly, because this system plugin does not
     * rely on content plugin events or the rendered article object.
     */
    protected function getCurrentArticle(): ?object
    {
        $id = (int) Factory::getApplication()->getInput()->getInt('id');

        if ($id <= 0) {
            return null;
        }

        // Non-deprecated replacement for Factory::getDbo() (works on J4-J6).
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select(
                [
                    $db->quoteName('a.id'),
                    $db->quoteName('a.title'),
                    $db->quoteName('a.introtext'),
                    $db->quoteName('a.fulltext'),
                    $db->quoteName('a.images'),
                    $db->quoteName('a.metadesc'),
                    $db->quoteName('a.metadata'),
                    $db->quoteName('a.state'),
                    $db->quoteName('a.created'),
                    $db->quoteName('a.modified'),
                    $db->quoteName('a.catid'),
                    $db->quoteName('c.title', 'category_title'),
                ]
            )
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'LEFT',
                $db->quoteName('#__categories', 'c'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('a.catid')
            )
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->bind(':id', $id, ParameterType::INTEGER);

        try {
            $db->setQuery($query);

            return $db->loadObject() ?: null;
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Set Twitter (X) meta tags.
     */
    protected function setTwitterMetadata(string $title, string $image, string $metadesc): void
    {
        $doc            = Factory::getApplication()->getDocument();
        $twitterAccount = trim((string) $this->params->get('twitteraccount', ''));
        $type           = (string) $this->params->get('type', 'summary');

        $doc->setMetaData('twitter:card', $type);

        if ($twitterAccount !== '') {
            $doc->setMetaData('twitter:site', $twitterAccount);
        }

        if ($title !== '') {
            $doc->setMetaData('twitter:title', $title);
        }

        if ($metadesc !== '') {
            $doc->setMetaData('twitter:description', $metadesc);
        }

        if ($image !== '') {
            $doc->setMetaData('twitter:image', $image);

            if ($title !== '') {
                $doc->setMetaData('twitter:image:alt', $title);
            }
        }
    }

    /**
     * Set Open Graph meta tags.
     */
    protected function setOpenGraphMetadata(string $title, string $image, string $metadesc, string $url, string $ogType = 'article'): void
    {
        $app      = Factory::getApplication();
        $doc      = $app->getDocument();
        $language = $app->getLanguage()->getTag();

        // Open Graph expects the "property" attribute, not "name".
        if ($title !== '') {
            $doc->setMetaData('og:title', $title, 'property');
        }

        if ($metadesc !== '') {
            $doc->setMetaData('og:description', $metadesc, 'property');
        }

        if ($image !== '') {
            $doc->setMetaData('og:image', $image, 'property');

            if ($title !== '') {
                $doc->setMetaData('og:image:alt', $title, 'property');
            }
        }

        $doc->setMetaData('og:url', $url, 'property');
        $doc->setMetaData('og:type', $ogType, 'property');
        $doc->setMetaData('og:locale', str_replace('-', '_', $language), 'property');
        $doc->setMetaData('og:site_name', (string) $app->get('sitename'), 'property');

        $facebookAppId = trim((string) $this->params->get('facebookappid', ''));

        if ($facebookAppId !== '') {
            $doc->setMetaData('fb:app_id', $facebookAppId, 'property');
        }
    }

    /**
     * Set article-specific Open Graph object properties (published/modified
     * time, section and tags). Only called on article pages.
     */
    protected function setArticleObjectMetadata($article): void
    {
        $doc = Factory::getApplication()->getDocument();

        $published = $this->toIso8601((string) ($article->created ?? ''));

        if ($published !== '') {
            $doc->setMetaData('article:published_time', $published, 'property');
        }

        $modified = $this->toIso8601((string) ($article->modified ?? ''));

        if ($modified !== '') {
            $doc->setMetaData('article:modified_time', $modified, 'property');
        }

        $section = trim((string) ($article->category_title ?? ''));

        if ($section !== '') {
            $doc->setMetaData('article:section', $this->cleanText($section), 'property');
        }

        foreach ($this->getArticleTags((int) ($article->id ?? 0)) as $tag) {
            $doc->setMetaData('article:tag', $tag, 'property');
        }
    }

    /**
     * Fetch published tag titles for an article.
     *
     * @return  string[]
     */
    protected function getArticleTags(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $context = 'com_content.article';
        $query   = $db->createQuery()
            ->select($db->quoteName('t.title'))
            ->from($db->quoteName('#__contentitem_tag_map', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__tags', 't'),
                $db->quoteName('t.id') . ' = ' . $db->quoteName('m.tag_id')
            )
            ->where($db->quoteName('m.type_alias') . ' = :context')
            ->where($db->quoteName('m.content_item_id') . ' = :id')
            ->where($db->quoteName('t.published') . ' = 1')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':id', $articleId, ParameterType::INTEGER);

        try {
            $db->setQuery($query);

            return array_map('strval', (array) $db->loadColumn());
        } catch (\RuntimeException $e) {
            return [];
        }
    }

    /**
     * Extract article image or use the plugin fallback image.
     */
    protected function setImage($images, $text): string
    {
        $fullImage = json_decode((string) $images);
        $image     = '';

        if (!empty($fullImage->image_fulltext)) {
            $image = (string) $fullImage->image_fulltext;
        } elseif (!empty($fullImage->image_intro)) {
            $image = (string) $fullImage->image_intro;
        } else {
            preg_match_all('|<img.*?src=[\'"](.*?)[\'"].*?>|i', (string) $text, $matches);

            if (!empty($matches[1][0])) {
                $image = (string) $matches[1][0];
            }
        }

        if ($image === '') {
            $image = (string) $this->params->get('default_image', '');
        }

        return $this->normalizeImageUrl($image);
    }

    /**
     * Convert relative image paths to absolute URLs.
     */
    protected function normalizeImageUrl(string $image): string
    {
        $image = trim($image);

        if ($image === '') {
            return '';
        }

        // Joomla media fields may append metadata after #.
        $image = explode('#', $image, 2)[0];

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        return Uri::root() . ltrim($image, '/');
    }

    /**
     * Extract article meta title.
     */
    protected function setMetatitle($metadata, $title): string
    {
        $metadata = json_decode((string) $metadata);

        if (!empty($metadata->metatitle)) {
            return (string) $metadata->metatitle;
        }

        return (string) $title;
    }

    /**
     * Extract article meta description. The maximum length is configurable
     * through the desc_limit parameter (default 159).
     */
    protected function setMetadesc($metadesc, $text): string
    {
        $limit    = (int) $this->params->get('desc_limit', 159);
        $limit    = $limit > 0 ? $limit : 159;
        $metadesc = trim((string) $metadesc);

        if ($metadesc !== '') {
            return $metadesc;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($this->stringLength($text) > $limit) {
            $text      = $this->stringSubstring($text, 0, $limit);
            $lastSpace = strrpos($text, ' ');

            if ($lastSpace !== false) {
                $text = substr($text, 0, $lastSpace);
            }

            $text .= '...';
        }

        return $text;
    }

    /**
     * Current URL without query string or fragment. This keeps /el/ or /en/
     * in multilingual sites, so og:url matches the final routed URL.
     */
    protected function getCurrentCleanUrl(): string
    {
        return Uri::getInstance()->toString(['scheme', 'host', 'port', 'path']);
    }

    /**
     * Normalise a title-like string for use in a meta tag: strip tags and
     * decode entities so social scrapers receive plain text.
     */
    protected function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Convert a Joomla/MySQL datetime to an ISO 8601 string, or '' if invalid.
     */
    protected function toIso8601(string $datetime): string
    {
        $datetime = trim($datetime);

        // strncmp keeps this working on PHP 7.2 (str_starts_with is PHP 8.0+).
        if ($datetime === '' || strncmp($datetime, '0000-00-00', 10) === 0) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function stringLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    protected function stringSubstring(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }
}
