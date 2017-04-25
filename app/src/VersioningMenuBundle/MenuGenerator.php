<?php

namespace Shopware\Devdocs\VersioningMenuBundle;

use Dflydev\DotAccessConfiguration\ConfigurationInterface;
use Sculpin\Core\Event\FormatEvent;
use Sculpin\Core\Permalink\SourcePermalinkFactoryInterface;
use Sculpin\Core\Sculpin;
use Sculpin\Core\Event\SourceSetEvent;
use Sculpin\Core\Source\SourceInterface;
use Sculpin\Core\Source\SourceSet;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @author Jan Bücker <jan@buecker.io>
 */
class MenuGenerator implements EventSubscriberInterface
{
    /**
     * @var array
     */
    protected $menu = [];

    /**
     * @var string
     */
    protected $siteUrl;

    /**
     * @var SourcePermalinkFactoryInterface
     */
    private $permalinkFactory;

    /**
     * @var ConfigurationInterface
     */
    private $configuration;

    /**
     * MenuGenerator constructor.
     * @param SourcePermalinkFactoryInterface $permalinkFactory
     */
    public function __construct(SourcePermalinkFactoryInterface $permalinkFactory, ConfigurationInterface $configuration)
    {
        $this->permalinkFactory = $permalinkFactory;
        $this->configuration = $configuration;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Sculpin::EVENT_BEFORE_RUN => 'beforeRun',
            Sculpin::EVENT_BEFORE_FORMAT => 'beforeFormat'
        ];
    }

    public function beforeFormat(FormatEvent $event)
    {
        $context = $event->formatContext()->data()->export();

        if (empty($context['page']['version'])) {
            return;
        }

        $siteUrl = $this->configuration->get('url') . '/' . $context['page']['version'];

        $event->formatContext()->data()->set('site.url', $siteUrl);
    }

    /**
     * @param SourceSetEvent $sourceSetEvent
     */
    public function beforeRun(SourceSetEvent $sourceSetEvent)
    {
        $sourceSet = $sourceSetEvent->sourceSet();
        $pages = [];

        foreach ($sourceSet->allSources() as $source) {
            /** @var \Sculpin\Core\Source\FileSource $source */

            if ($source->isGenerated() || !$source->canBeFormatted()) {
                // Skip generated sources.
                // Only takes pages that can be formatted (AKA *.md) and skip images, CSS, JS, ...
                continue;
            }

            list($version, $trash) = explode('/', $source->relativePathname());

            if (!preg_match('#(v\d\.\d\.\d|latest)#', $version)) {
                $version = null;
            }

            $source->data()->set('version', $version);
            $source->data()->set(
                'url',
                $this->configuration->get('url') . '/' . trim($this->permalinkFactory->create($source)->relativeUrlPath(), '/') . '/'
            );

            $menuItem = $this->buildMenuItem($source);

            if (!$menuItem) {
                continue;
            }

            $pages[$version][] = $menuItem;
        }

        $versions = array_keys($pages);
        $latestVersion = array_pop($versions);
        $pages['latest'] = $this->getLatestVersion($sourceSet, $latestVersion);

        $versions = array_filter(array_keys($pages), function ($version) {
            return (bool) preg_match('#(v\d\.\d\.\d|latest)#', $version);
        });

        $this->setVersions($sourceSet, $versions);

        foreach ($pages as $version => $content) {
            $this->setMenu($sourceSet, $version, $this->buildMenu($content));
        }
    }

    /**
     * @param SourceInterface $source
     *
     * @return array|null
     */
    private function buildMenuItem(SourceInterface $source)
    {
        $menuTitle = $source->data()->get('menu_title');

        if (!$menuTitle) {
            return null;
        }

        $group = $source->data()->get('group') ?: null;
        $subgroup = null;

        if ($group) {
            $subgroup = $source->data()->get('subgroup') ?: null;
        }

        return [
            'id' => $group.$subgroup.$menuTitle,
            'menu_title' => $menuTitle,
            'menu_order' => $source->data()->get('menu_order') ?: 1,
            'menu_style' => $source->data()->get('menu_style') ?: null,
            'menu_chapter' => (bool) $source->data()->get('menu_chapter'),
            'group' => $group,
            'subgroup' => $subgroup,
            'url' => $source->data()->get('url'),
            'parent' => $group.$subgroup,
        ];
    }

    /**
     * Now that the menu structure has been created, inject it back to the page.
     *
     * @param SourceSet $sourceSet
     * @param string $version
     * @param array $menu
     *
     * @return void
     */
    protected function setMenu(SourceSet $sourceSet, $version, array $menu)
    {
        // Second loop to set the menu which was initialized during the first loop
        foreach ($sourceSet->allSources() as $source) {
            /** @var \Sculpin\Core\Source\FileSource $source */

            if (!$source->canBeFormatted()) {
                // Skip generated sources.
                // Only takes pages that can be formatted (AKA *.md)
                continue;
            }

            if (!preg_match('#^'.$version.'/#', $source->relativePathname())) {
                continue;
            }

            $source->data()->set('menu', $menu);
        }
    }

    /**
     * @param array $elements
     * @param string $parentId
     * @return array
     */
    private function buildMenu(array &$elements, $parentId = null)
    {
        $branch = [];

        foreach ($elements as $element) {
            if ($element['parent'] == $parentId) {
                $children = $this->buildMenu($elements, $element['id']);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[$element['id']] = $element;
                unset($elements[$element['id']]);
            }
        }

        usort($branch, function ($a, $b) {
            return $a['menu_order'] - $b['menu_order'];
        });

        return $branch;
    }

    /**
     * @param SourceSet $sourceSet
     * @param string $latestVersion
     *
     * @return array
     */
    private function getLatestVersion(SourceSet $sourceSet, $latestVersion)
    {
        $pages = [];

        foreach ($sourceSet->allSources() as $source) {
            /** @var \Sculpin\Core\Source\FileSource $source */

            if ($source->isGenerated() || !$source->canBeFormatted()) {
                // Skip generated sources.
                // Only takes pages that can be formatted (AKA *.md) and skip images, CSS, JS, ...
                continue;
            }

            if (!preg_match('#^'.$latestVersion.'/#', $source->relativePathname())) {
                continue;
            }

            $targetPath = preg_replace('#'.$latestVersion.'/#', 'latest/', $source->relativePathname());

            $id = $source->sourceId() . '-latest';
            $latestSource = $source->duplicate($id, [
                'relativePathname' => $targetPath,
            ]);
            $latestSource->setIsGenerated();

            $source->data()->set('version', 'latest');
            $source->data()->set(
                'url',
                $this->configuration->get('url') . '/' . trim($this->permalinkFactory->create($latestSource)->relativeUrlPath(), '/') . '/'
            );

            $sourceSet->mergeSource($latestSource);

            $menuItem = $this->buildMenuItem($latestSource);

            if (!$menuItem) {
                continue;
            }

            $pages[] = $menuItem;
        }

        return $pages;
    }

    /**
     * @param SourceSet $sourceSet
     * @param array $versions
     */
    private function setVersions(SourceSet $sourceSet, $versions)
    {
        // Second loop to set the menu which was initialized during the first loop
        foreach ($sourceSet->allSources() as $source) {
            /** @var \Sculpin\Core\Source\FileSource $source */

            if (!$source->canBeFormatted()) {
                // Only takes pages that can be formatted (AKA *.md)
                continue;
            }

            $source->data()->set('versions', $versions);
        }
    }
}
