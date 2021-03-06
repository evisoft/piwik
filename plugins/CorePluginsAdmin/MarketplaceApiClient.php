<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik_Plugins
 * @package CorePluginsAdmin
 */
namespace Piwik\Plugins\CorePluginsAdmin;

use Piwik\CacheFile;
use Piwik\Http;
use Piwik\PluginsManager;
use Piwik\Version;

/**
 *
 * @package CorePluginsAdmin
 */
class MarketplaceApiClient
{
    const CACHE_TIMEOUT_IN_SECONDS = 1200;
    const HTTP_REQUEST_TIMEOUT = 30;

    private $domain = 'http://plugins.piwik.org';

    /**
     * @var CacheFile
     */
    private $cache = null;

    public function __construct()
    {
        $this->cache = new CacheFile('marketplace', self::CACHE_TIMEOUT_IN_SECONDS);
    }

    public static function clearAllCacheEntries()
    {
        $cache = new CacheFile('marketplace');
        $cache->deleteAll();
    }

    public function getPluginInfo($name)
    {
        $action = sprintf('plugins/%s/info', $name);

        return $this->fetch($action, array());
    }

    public function download($pluginOrThemeName, $target)
    {
        $downloadUrl = $this->getDownloadUrl($pluginOrThemeName);

        if (empty($downloadUrl)) {
            return false;
        }

        $success = Http::fetchRemoteFile($downloadUrl, $target, 0, static::HTTP_REQUEST_TIMEOUT);

        return $success;
    }

    /**
     * @param \Piwik\Plugin[] $plugins
     * @return array|mixed
     */
    public function checkUpdates($plugins)
    {
        $params = array();

        foreach ($plugins as $plugin) {
            $pluginName = $plugin->getPluginName();
            if (!PluginsManager::getInstance()->isPluginBundledWithCore($pluginName)) {
                $params[] = array('name' => $plugin->getPluginName(), 'version' => $plugin->getVersion());
            }
        }

        $params = array('plugins' => $params);

        $hasUpdates = $this->fetch('plugins/checkUpdates', array('plugins' => json_encode($params)));

        if (empty($hasUpdates)) {
            return array();
        }

        return $hasUpdates;
    }

    /**
     * @param  \Piwik\Plugin[] $plugins
     * @param  bool $themesOnly
     * @return array
     */
    public function getInfoOfPluginsHavingUpdate($plugins, $themesOnly)
    {
        $hasUpdates = $this->checkUpdates($plugins);

        $pluginDetails = array();

        foreach ($hasUpdates as $pluginHavingUpdate) {
            $plugin = $this->getPluginInfo($pluginHavingUpdate['name']);

            if (!empty($plugin['isTheme']) == $themesOnly) {
                $pluginDetails[] = $plugin;
            }
        }

        return $pluginDetails;
    }

    public function searchForPlugins($keywords, $query, $sort)
    {
        $response = $this->fetch('plugins', array('keywords' => $keywords, 'query' => $query, 'sort' => $sort));

        if (!empty($response['plugins'])) {
            return $response['plugins'];
        }

        return array();
    }

    public function searchForThemes($keywords, $query, $sort)
    {
        $response = $this->fetch('themes', array('keywords' => $keywords, 'query' => $query, 'sort' => $sort));

        if (!empty($response['plugins'])) {
            return $response['plugins'];
        }

        return array();
    }

    private function fetch($action, $params)
    {
        ksort($params);
        $query = http_build_query($params);
        $result = $this->getCachedResult($action, $query);

        if (false === $result) {
            $endpoint = $this->domain . '/api/1.0/';
            $url = sprintf('%s%s?%s', $endpoint, $action, $query);
            $response = Http::sendHttpRequest($url, static::HTTP_REQUEST_TIMEOUT);
            $result = json_decode($response, true);

            if (is_null($result)) {
                $message = sprintf('There was an error reading the response from the Marketplace: %s. Please try again later.',
                    substr($response, 0, 50));
                throw new MarketplaceApiException($message);
            }

            if (!empty($result['error'])) {
                throw new MarketplaceApiException($result['error']);
            }

            $this->cacheResult($action, $query, $result);
        }

        return $result;
    }

    private function getCachedResult($action, $query)
    {
        $cacheKey = $this->getCacheKey($action, $query);

        return $this->cache->get($cacheKey);
    }

    private function cacheResult($action, $query, $result)
    {
        $cacheKey = $this->getCacheKey($action, $query);

        $this->cache->set($cacheKey, $result);
    }

    private function getCacheKey($action, $query)
    {
        return sprintf('api.1.0.%s.%s', str_replace('/', '.', $action), md5($query));
    }

    /**
     * @param  $pluginOrThemeName
     * @throws MarketplaceApiException
     * @return string
     */
    public function getDownloadUrl($pluginOrThemeName)
    {
        $plugin = $this->getPluginInfo($pluginOrThemeName);

        if (empty($plugin['versions'])) {
            throw new MarketplaceApiException('Plugin has no versions.');
        }

        $latestVersion = array_pop($plugin['versions']);
        $downloadUrl = $latestVersion['download'];

        return $this->domain . $downloadUrl . '?coreVersion=' . Version::VERSION;
    }

}
