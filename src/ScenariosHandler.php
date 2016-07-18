<?php

namespace Drupal\scenarios;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\ProxyClass\Extension\ModuleInstaller;
use Drupal\Core\Extension\InfoParser;
use Drupal\Core\Extension\ThemeHandler;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\Plugin\MigrationPluginManager;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate_tools\DrushLogMigrateMessage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ScenariosHandler.
 *
 * @package Drupal\scenarios
 */
class ScenariosHandler implements ContainerInjectionInterface {

  /**
   * Drupal\Core\Extension\ModuleHandler definition.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * Drupal\Core\ProxyClass\Extension\ModuleInstaller definition.
   *
   * @var \Drupal\Core\ProxyClass\Extension\ModuleInstaller
   */
  protected $moduleInstaller;

  /**
   * Drupal\Core\Extension\ThemeHandler definition.
   *
   * @var \Drupal\Core\Extension\ThemeHandler
   */
  protected $infoParser;

  /**
   * Drupal\Core\Extension\InfoParser definition.
   *
   * @var \Drupal\Core\Extension\InfoParser
   */
  protected $themeHandler;

  /**
   * Drupal\migrate\Plugin\MigrationPluginManager definition.
   *
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  protected $migrationPluginManager;

  /**
   * Constructor.
   *
   * @var \Drupal\Core\Extension\ModuleHandler
   * @var \Drupal\Core\ProxyClass\Extension\ModuleInstaller
   * @var \Drupal\Core\Extension\InfoParser
   * @var \Drupal\Core\Extension\ThemeHandler
   * @var \Drupal\migrate\Plugin\MigrationPluginManager
   */
  public function __construct(ModuleHandler $module_handler, ModuleInstaller $module_installer, InfoParser $info_parser, ThemeHandler $theme_handler, MigrationPluginManager $migration_plugin_manager) {
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->infoParser = $info_parser;
    $this->themeHandler = $theme_handler;
    $this->migrationPluginManager = $migration_plugin_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('info_parser'),
      $container->get('theme_handler'),
      $container->get('plugin.manager.migration')
    );
  }

  /**
   * Handling for messages.
   *
   * @param string $message
   * @param string $type
   * @param bool $repeat
   */
  public function setMessage($message, $type = 'status', $repeat = FALSE) {
    if (PHP_SAPI === 'cli' && function_exists('drush_log')) {
      $type = ($type == 'status' ? 'ok' : $type);
      drush_log($message, $type);
    }
    else {
      drupal_set_message($message, $type, $repeat);
    }
  }

  /**
   * Handling for errors.
   *
   * @param string $message
   */
  public function setError($message) {
    if (PHP_SAPI === 'cli' && function_exists('drush_set_error')) {
      drush_set_error('ERROR', $message);
    }
    else {
      drupal_set_message($message, 'error');
    }
  }

  /**
   * Retrieve alias context for Drush.
   *
   * @return null|string
   */
  public function getAlias() {
    $alias = null;
    if (PHP_SAPI === 'cli' && function_exists('drush_get_context')) {
      $alias_context = drush_get_context('alias');
      $alias = !empty($alias_context) ? $alias_context : '@self';
    }
    return $alias;
  }

  /**
   * Set up the logger.
   *
   * @param $alias
   * @return \Drupal\migrate\MigrateMessage|\Drupal\migrate_tools\DrushLogMigrateMessage
   */
  public function getLog($alias) {
    if ($alias != null && function_exists('drush_log')) {
      return new DrushLogMigrateMessage();
    }
    else {
      return new MigrateMessage();
    }
  }

  public function getScenarioInfo($scenario) {
    $filename = drupal_get_filename('module', $scenario);
    return $this->infoParser->parse($filename);
  }

  /**
   * @param $scenario
   * @return string | null
   */
  public function getScreenshot($scenario) {
    $info = $this->getScenarioInfo($scenario);
    $themes = $this->themeHandler->rebuildThemeData();
    if (!empty($info['scenarios_theme']) && !empty($themes[$info['scenarios_theme']]) && file_exists($themes[$info['scenarios_theme']]->info['screenshot'])) {
      return $themes[$info['scenarios_theme']]->info['screenshot'];
    }
    return null;
  }

  /**
   * @param string $scenario
   */
  function scenarioEnable($scenario) {
    if (!$scenario) {
      $this->setError(t('You must specify a scenario machine name, e.g. dfs_tec.'));
      return;
    }

    // Get the Drush alias if necessary or return null.
    $alias = $this->getAlias();

    // Get the correct log for migrate messages.
    $log = $this->getLog($alias);

    // Check if scenario is already enabled then install it.
    if (!$this->moduleHandler->moduleExists($scenario)) {

      // Get the scenario module info.
      $info = $this->getScenarioInfo($scenario);

      // If the scenario specifies a theme, enable it before installing the
      // scenario itself.
      if (!empty($info['scenarios_theme']) && !$this->themeHandler->themeExists($info['scenarios_theme'])) {
        if ($this->themeHandler->install([$info['scenarios_theme']])) {
          $this->setMessage(t('Installed @scenario scenario theme @theme.', [
            '@scenario' => $scenario,
            '@theme' => $info['scenarios_theme']
          ]));
        }
      }

      // Install the scenario module.
      if ($this->moduleInstaller->install([$scenario])) {
        $this->setMessage(t('Installed @name scenario module.', ['@name' => $scenario]));
      }
    }
    else {
      $this->setError( t('The scenario @scenario is already enabled.', ['@scenario' => $scenario]));
      return;
    }

    // If we have pending batches, process them now.
    if (batch_get()) {
      if ($alias != null && function_exists('drush_backend_batch_process')) {
        drush_backend_batch_process();
      }
      else {
        batch_process();
      }
    }

    // Retrieve the migrations for the given scenario.
    $migrations = scenarios_scenario_migrations($scenario);

    // Run the migrations in the provided order.
    $migration_manager = $this->migrationPluginManager;
    $migration_manager->clearCachedDefinitions();

    foreach ($migrations as $migration) {
      $migration = $migration_manager->createInstance($migration);
      $executable = new MigrateExecutable($migration, $log);
      if ($executable->import()) {
        $this->setMessage(t('Imported "@name" migration.', ['@name' => $migration->label()]));
      }
      $this->moduleHandler->invokeAll('scenarios_migration_finished', [$migration]);
    }

    // Rebuild cache after enabling scenario.
    if ($alias != null && function_exists('drush_invoke_process')) {
      drush_invoke_process($alias, 'cache-rebuild');
    }
    else {
      drupal_flush_all_caches();
    }
  }

  /**
   * @param string $scenario
   */
  public function scenarioUninstall($scenario) {
    if (!$scenario) {
      $this->setError(t('You must specify a scenario machine name, e.g. dfs_tec.'));
      return;
    }

    // Get the Drush alias if necessary or return null.
    $alias = $this->getAlias();

    // Check if scenario is already enabled and uninstall it.
    if ($this->moduleHandler->moduleExists($scenario)) {
      $this->setError(t('Uninstalled @name scenario module.', ['@name' => $scenario]));
      return;
    }

    // Retrieve the migrations for the given scenario.
    $migrations = scenarios_scenario_migrations($scenario);

    // Reverse the order of the migrations.
    $migrations = array_reverse($migrations);

    $migration_manager = $this->migrationPluginManager;
    $migration_manager->clearCachedDefinitions();

    // Rollback the migrations in the modified order.
    foreach ($migrations as $migration) {
      $log = $this->getLog($alias);

      /** @var \Drupal\migrate\Plugin\Migration $migration */
      $migration = $migration_manager->createInstance($migration);

      $executable = new MigrateExecutable($migration, $log);
      if ($executable->rollback()) {
        $this->setMessage(t('Uninstalled @name scenario module.', ['@name' => $scenario]));
      }
    }

    // Uninstall the scenario.
    if ($this->moduleInstaller->uninstall([$scenario])) {
      $this->setMessage(t('Uninstalled @name scenario module.', ['@name' => $scenario]));
    }
  }

  /**
   * @param string $scenario
   */
  public function scenarioReset($scenario) {
    $this->setMessage(t('Initiated reset of @name scenario module.', ['@name' => $scenario]), 'warning');
    // Uninstall the scenario.
    $this->scenarioUninstall($scenario);
    // Enable the scenario.
    $this->scenarioUninstall($scenario);
  }

}