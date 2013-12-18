<?php
/*
  +------------------------------------------------------------------------+
  | PhalconEye CMS                                                         |
  +------------------------------------------------------------------------+
  | Copyright (c) 2013 PhalconEye Team (http://phalconeye.com/)            |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconeye.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Author: Ivan Vorontsov <ivan.vorontsov@phalconeye.com>                 |
  +------------------------------------------------------------------------+
*/

namespace Core;

use Core\Model\Settings;
use Core\Model\Widget;
use Engine\Bootstrap as EngineBootstrap;
use Engine\Profiler;
use Engine\Translation\Db as TranslationDb;
use Engine\Widget\Storage;
use Phalcon\Config;
use Phalcon\DI;
use Phalcon\Mvc\View;
use Phalcon\Translate\Adapter\NativeArray as TranslateArray;
use User\Model\User;

/**
 * Core Bootstrap.
 *
 * @category  PhalconEye
 * @package   Core
 * @author    Ivan Vorontsov <ivan.vorontsov@phalconeye.com>
 * @copyright 2013 PhalconEye Team
 * @license   New BSD License
 * @link      http://phalconeye.com/
 */
class Bootstrap extends EngineBootstrap
{
    /**
     * Current module name.
     *
     * @var string
     */
    protected $_moduleName = "Core";

    /**
     * Register the services.
     *
     * @param DI $di Dependency injection.
     *
     * @return void
     */
    public function registerServices($di)
    {
        parent::registerServices($di);

        $config = $di->get('config');
        $this->_initLocale($di, $config);
        if (!$config->installed) {
            return;
        }

        // Remove profiler for non-user.
        if (!User::getViewer()->id) {
            $di->remove('profiler');
        }
        $this->_initWidgets($di);
    }

    /**
     * Prepare widgets metadata for Engine.
     *
     * @param DI $di Dependency injection.
     *
     * @return void
     */
    private function _initWidgets(DI $di)
    {
        $cache = $di->get('cacheData');
        $cacheKey = "widgets_metadata.cache";
        $widgets = $cache->get($cacheKey);

        if ($widgets === null) {
            $widgetObjects = Widget::find();
            $widgets = [];
            foreach ($widgetObjects as $object) {
                $widgets[$object->id] = $object;
            }

            $cache->save($cacheKey, $widgets, 2592000); // 30 days.
        }
        Storage::setWidgets($widgets);
    }

    /**
     * Init locale.
     *
     * @param DI     $di     Dependency injection.
     * @param Config $config Dependency injection.
     *
     * @return void
     */
    private function _initLocale(DI $di, Config $config)
    {
        if ($config->installed) {
            $locale = $di->get('session')->get('locale', Settings::getSetting('system_default_language'));
        } else {
            $locale = $di->get('session')->get('locale', 'en');
        }

        $translate = null;

        if (!$di->get('config')->application->debug || !$config->installed) {
            $messages = [];
            if (file_exists(ROOT_PATH . "/app/var/languages/" . $locale . ".php")) {
                require ROOT_PATH . "/app/var/languages/" . $locale . ".php";
            } else {
                if (file_exists(ROOT_PATH . "/app/var/languages/en.php")) {
                    // fallback to default
                    require ROOT_PATH . "/app/var/languages/en.php";
                }
            }

            $translate = new TranslateArray(
                [
                    "content" => $messages
                ]
            );
        } else {
            $translate = new TranslationDb(
                [
                    'db' => $di->get('db'),
                    'locale' => $locale,
                    'model' => 'Core\Model\Language',
                    'translationModel' => 'Core\Model\LanguageTranslation'
                ]
            );
        }

        $di->set('trans', $translate);
    }

    /**
     * Handle Profiler.
     *
     * @param DI     $di     Dependency injection.
     * @param Config $config Dependency injection.
     *
     * @return void
     * @TODO: Refactor this.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public static function handleProfiler(DI $di, Config $config)
    {
        if (!$config->application->debug || !$di->has('profiler')) {
            return;
        }

        // check admin area
        if (substr($di->get('dispatcher')->getControllerName(), 0, 5) == 'Admin') {
            return;
        }

        $viewer = User::getViewer();
        if (!$viewer->id || !$viewer->isAdmin()) {
            return;
        }

        /** @var View $view */
        $view = $di->get('view');
        $view->setViewsDir(__DIR__ . '/View/partials/');
        $view->setPartialsDir('profiler/');
        $view->disableLevel(View::LEVEL_LAYOUT);
        $view->setMainView('profiler/layout');

        $render = function ($template, $params) use ($view) {
            return $view->getRender('profiler', $template, $params);
        };
        $renderTitle = function ($title) use ($render) {
            return $render('title', ['title' => $title]);
        };
        $renderElement = function ($title, $value = null, $tag = null, $noCode = null) use ($render) {
            return $render('element', ['title' => $title, 'value' => $value, 'tag' => $tag, 'noCode' => $noCode]);
        };

        $profiler = $di->get('profiler');
        $router = $di->get('router');
        $dbProfiler = $profiler->getDbProfiler();
        $dbProfiles = $dbProfiler->getProfiles();
        $handlerValues = [];

        //////////////////////////////////////
        /// Config.
        //////////////////////////////////////
        $htmlConfig = '';
        foreach ($config->toArray() as $key => $data) {
            if (!is_array($data) || empty($data)) {
                continue;
            }

            $htmlConfig .= $renderTitle(ucfirst($key));
            foreach ($data as $key2 => $data2) {
                if (is_array($data2)) {
                    foreach ($data2 as $key3 => $data3) {
                        if (!is_array($data2)) {
                            $htmlConfig .= $renderElement(ucfirst($key3), $data3);
                        }
                    }
                } else {
                    $htmlConfig .= $renderElement(ucfirst($key2), $data2);
                }
            }

            $htmlConfig .= '<br/>';
        }

        //////////////////////////////////////
        /// Router.
        //////////////////////////////////////
        $handlerValues['router'] = ucfirst($router->getControllerName()) .
            'Controller::' .
            ucfirst($router->getActionName()) . 'Action';
        $htmlRouter = $renderElement('POST data', print_r($_POST, true), 'pre');
        $htmlRouter .= $renderElement('GET data', print_r($_GET, true), 'pre');
        $htmlRouter .= $renderElement('Module', ucfirst($router->getModuleName()));
        $htmlRouter .= $renderElement('Controller', ucfirst($router->getControllerName()));
        $htmlRouter .= $renderElement('Action', ucfirst($router->getActionName()));
        if ($router->getMatchedRoute()) {
            $htmlRouter .= $renderElement('Matched Route', ucfirst($router->getMatchedRoute()->getName()));
        }

        //////////////////////////////////////
        /// Memory.
        //////////////////////////////////////
        $memoryData = memory_get_usage();
        $memoryLimit = ((int)ini_get('memory_limit')) * 1024 * 1024;
        $currentMemoryPercent = round($memoryData / ($memoryLimit / 100));
        $colorClass = (
        $currentMemoryPercent > 30 ? ($currentMemoryPercent < 75 ?
            'item-normal' : 'item-bad') :
            'item-good'
        );
        $handlerValues['memory'] = [
            'class' => $colorClass,
            'value' => round($memoryData / 1024, 2)
        ];

        $htmlMemory = '';
        foreach (Profiler::$objectTypes as $type) {
            $data = $profiler->getData('memory', $type);
            if (empty($data)) {
                continue;
            }

            $htmlMemory .= $renderTitle(ucfirst($type));
            foreach ($data as $class => $memoryValue) {
                $memory = round($memoryValue / 1024, 2);
                $htmlMemory .= $renderElement(str_replace(ROOT_PATH, '', $class), $memory . ' kb');
            }

            $htmlMemory .= '<br/>';
        }

        //////////////////////////////////////
        /// Time.
        //////////////////////////////////////
        $timeData = round((microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"]) * 1000, 2);
        $colorClass = ($timeData > 200 ? ($timeData < 500 ? 'item-normal' : 'item-bad') : 'item-good');
        $handlerValues['time'] = [
            'class' => $colorClass,
            'value' => $timeData
        ];

        $htmlTime = '';
        foreach (Profiler::$objectTypes as $type) {
            $data = $profiler->getData('time', $type);
            if (empty($data)) {
                continue;
            }

            $htmlTime .= $renderTitle(ucfirst($type));
            foreach ($data as $class => $timeValue) {
                $msTime = round($timeValue * 1000, 2);
                $timeData -= $msTime;
                $htmlTime .= $renderElement(str_replace(ROOT_PATH, '', $class), $msTime . ' ms');
            }

            $htmlTime .= '<br/>';
        }
        $htmlTime .= $renderTitle('Other');
        $htmlTime .= $renderElement('Time from request received', $timeData . ' ms');
        $htmlTime .= '<br/>';

        //////////////////////////////////////
        /// Files.
        //////////////////////////////////////
        $filesData = get_included_files();
        $handlerValues['files'] = count($filesData);

        $htmlFiles = '';
        foreach ($filesData as $file) {
            $filesize = round(filesize($file) / 1024, 2);
            $htmlFiles .= $renderElement(str_replace(ROOT_PATH, '', $file), $filesize . ' kb');
        }

        //////////////////////////////////////
        /// SQL.
        //////////////////////////////////////
        $handlerValues['sql'] = $dbProfiler->getNumberTotalStatements();

        $htmlSql = 'No Sql';
        if (!empty($dbProfiles)) {
            $longestQuery = '';
            $longestQueryTime = 0;

            $htmlSql = $renderElement('Total count', $dbProfiler->getNumberTotalStatements(), null, true);
            $htmlSql .= $renderElement(
                'Total time',
                round($dbProfiler->getTotalElapsedSeconds() * 1000, 4),
                null,
                true
            );
            $htmlSql .= $renderElement('Longest query', '<span class="code">%s</span> (%s ms)<br/>', null, true);

            foreach ($dbProfiles as $profile) {
                if ($profile->getTotalElapsedSeconds() > $longestQueryTime) {
                    $longestQueryTime = $profile->getTotalElapsedSeconds();
                    $longestQuery = $profile->getSQLStatement();
                }
                $htmlSql .= $renderElement('SQL', $profile->getSQLStatement());
                $htmlSql .= $renderElement(
                    'Time',
                    round($profile->getTotalElapsedSeconds() * 1000, 4) . ' ms<br/>',
                    null,
                    true
                );
            }

            $htmlSql = sprintf($htmlSql, $longestQuery, round($longestQueryTime * 1000, 4));
        }

        //////////////////////////////////////
        /// Errors.
        //////////////////////////////////////
        $errorsData = $profiler->getData('error');
        $errorsCount = count($errorsData);
        $colorClass = ($errorsCount == 0 ? 'item-good' : 'item-bad');
        $handlerValues['errors'] = [
            'class' => $colorClass,
            'value' => $errorsCount
        ];

        $htmlErrors = ($errorsCount == 0 ? 'No Errors' : '');
        foreach ($errorsData as $data) {
            $htmlErrors .= $renderElement($data['error'], str_replace('#', '<br/>#', $data['trace']));
        }

        $output = $render(
            'main',
            [
                'handlerValues' => $handlerValues,
                'htmlConfig' => $htmlConfig,
                'htmlRouter' => $htmlRouter,
                'htmlMemory' => $htmlMemory,
                'htmlTime' => $htmlTime,
                'htmlFiles' => $htmlFiles,
                'htmlSql' => $htmlSql,
                'htmlErrors' => $htmlErrors,
            ]
        );
        echo trim(preg_replace('/\s\s+/', ' ', $output));
    }
}