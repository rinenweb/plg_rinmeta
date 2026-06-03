<?php
/**
 * System Plugin for Joomla! - RinMeta
 *
 * Adds Open Graph and Twitter metadata to article pages and, optionally,
 * to the language-specific homepage. Designed for Joomla sites where the
 * homepage is module-only and content plugin events are not fired.
 *
 * @author     rinenweb.eu <info@rinenweb.eu>
 * @license    GNU GPL v3 or later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
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

        $doc = Factory::getDocument();

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
        $input = Factory::getApplication()->input;

        return $input->getCmd('option') === 'com_content'
            && $input->getCmd('view') === 'article'
            && $input->getInt('id') > 0;
    }

    /**
     * Detect whether the current menu item is the language-specific homepage.
     */
    protected function isHomePage(): bool
    {
        $app = Factory::getApplication();
        $menu = $app->getMenu();
        $active = $menu->getActive();

        if (!$active) {
            return false;
        }

        $language = Factory::getLanguage()->getTag();
        $default = $menu->getDefault($language) ?: $menu->getDefault('*') ?: $menu->getDefault();

        return $default && (int) $active->id === (int) $default->id;
    }

    /**
     * Set metadata for the language-specific homepage.
     */
    protected function setHomePageMetadata(): void
    {
        $app = Factory::getApplication();
        $doc = Factory::getDocument();
        $config = Factory::getConfig();
        $active = $app->getMenu()->getActive();
        $menuParams = $active ? $active->getParams() : null;

        $title = trim((string) $this->params->get('homepage_title', ''));

        if ($title === '' && $menuParams) {
            $title = trim((string) ($menuParams->get('page_title') ?: $active->title));
        }

        if ($title === '') {
            $title = trim((string) ($doc->getTitle() ?: $config->get('sitename')));
        }

        $metadesc = trim((string) $this->params->get('homepage_description', ''));

        if ($metadesc === '' && $menuParams) {
            $metadesc = trim((string) $menuParams->get('menu-meta_description', ''));
        }

        if ($metadesc === '') {
            $metadesc = trim((string) ($doc->getDescription() ?: $config->get('MetaDesc', '')));
        }

        $image = $this->normalizeImageUrl((string) $this->params->get('homepage_image', ''));

        if ($image === '') {
            $image = $this->normalizeImageUrl((string) $this->params->get('default_image', ''));
        }

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

        $text = trim((string) ($article->introtext ?? '') . ' ' . (string) ($article->fulltext ?? ''));
        $title = $this->setMetatitle($article->metadata ?? '', $article->title ?? '');
        $image = $this->setImage($article->images ?? '', $text);
        $metadesc = $this->setMetadesc($article->metadesc ?? '', $text);

        $this->setTwitterMetadata($title, $image, $metadesc);
        $this->setOpenGraphMetadata($title, $image, $metadesc, $this->getCurrentCleanUrl(), 'article');
    }

    /**
     * Load the current article directly, because this system plugin does not
     * rely on content plugin events or the rendered article object.
     */
    protected function getCurrentArticle(): ?object
    {
        $id = (int) Factory::getApplication()->input->getInt('id');

        if ($id <= 0) {
            return null;
        }

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('id'),
                $db->quoteName('title'),
                $db->quoteName('introtext'),
                $db->quoteName('fulltext'),
                $db->quoteName('images'),
                $db->quoteName('metadesc'),
                $db->quoteName('metadata'),
                $db->quoteName('state'),
            ])
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':id', $id, ParameterType::INTEGER);

        try {
            $db->setQuery($query);

            return $db->loadObject() ?: null;
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Set Twitter meta tags.
     */
    protected function setTwitterMetadata(string $title, string $image, string $metadesc): void
    {
        $doc = Factory::getDocument();
        $twitterAccount = trim((string) $this->params->get('twitteraccount', ''));
        $type = (string) $this->params->get('type', 'summary');

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
        }
    }

    /**
     * Set Open Graph meta tags.
     */
    protected function setOpenGraphMetadata(string $title, string $image, string $metadesc, string $url, string $ogType = 'article'): void
    {
        $doc = Factory::getDocument();
        $config = Factory::getConfig();
        $language = Factory::getLanguage()->getTag();

        // Open Graph expects the "property" attribute, not "name".
        if ($title !== '') {
            $doc->setMetaData('og:title', $title, 'property');
        }

        if ($metadesc !== '') {
            $doc->setMetaData('og:description', $metadesc, 'property');
        }

        if ($image !== '') {
            $doc->setMetaData('og:image', $image, 'property');
        }

        $doc->setMetaData('og:url', $url, 'property');
        $doc->setMetaData('og:type', $ogType, 'property');
        $doc->setMetaData('og:locale', str_replace('-', '_', $language), 'property');
        $doc->setMetaData('og:site_name', (string) $config->get('sitename'), 'property');

        $facebookAppId = trim((string) $this->params->get('facebookappid', ''));

        if ($facebookAppId !== '') {
            $doc->setMetaData('fb:app_id', $facebookAppId, 'property');
        }
    }

    /**
     * Extract article image or use the plugin fallback image.
     */
    protected function setImage($images, $text): string
    {
        $fullImage = json_decode((string) $images);
        $image = '';

        if (!empty($fullImage->image_fulltext)) {
            $image = (string) $fullImage->image_fulltext;
        } elseif (!empty($fullImage->image_intro)) {
            $image = (string) $fullImage->image_intro;
        } else {
            preg_match_all('|<img.*?src=[\'\"](.*?)[\'\"].*?>|i', (string) $text, $matches);

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
     * Extract article meta description.
     */
    protected function setMetadesc($metadesc, $text, $limit = 159): string
    {
        $metadesc = trim((string) $metadesc);

        if ($metadesc !== '') {
            return $metadesc;
        }

        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($this->stringLength($text) > $limit) {
            $text = $this->stringSubstring($text, 0, $limit);
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

    protected function stringLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    protected function stringSubstring(string $text, int $start, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, $start, $length, 'UTF-8') : substr($text, $start, $length);
    }
}
