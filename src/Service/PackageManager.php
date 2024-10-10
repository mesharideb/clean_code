<?php

declare(strict_types=1);

namespace Drupal\clean_code\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Provides functionality to manage Composer packages.
 *
 * This service handles the installation, removal, and checking of Composer
 * packages for the Clean Code module. It interacts with Composer through
 * system processes and manages the configuration of selected packages.
 */
class PackageManager {

  use StringTranslationTrait;

  /**
   * Constants representing different actions that can be performed.
   */
  private const ACTION_REQUIRE = 'require';
  private const ACTION_REMOVE = 'remove';
  private const ACTION_SHOW = 'show';

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private ModuleHandlerInterface $moduleHandler;

  /**
   * The SymfonyStyle IO object for user interaction.
   *
   * @var \Symfony\Component\Console\Style\SymfonyStyle|null
   */
  private ?SymfonyStyle $io = NULL;


  /**
   * The Drupal root directory.
   *
   * @var string
   */
  private string $drupalRoot;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * Constructs a new PackageManager object.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param string $drupalRoot
   *   The Drupal root directory.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $configFactory,
    string $drupalRoot,
  ) {
    $this->logger = $logger_factory->get('clean_code');
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $configFactory;
    $this->drupalRoot = $drupalRoot;
  }

  /**
   * Sets the SymfonyStyle IO object.
   *
   * @param \Symfony\Component\Console\Style\SymfonyStyle $io
   *   The SymfonyStyle IO object.
   */
  public function setIo(SymfonyStyle $io): void {
    $this->io = $io;
  }

  /**
   * Installs a list of Composer packages.
   *
   * @param array $packages
   *   An array of package names to install.
   *
   * @throws \Exception
   *   If there's an error during package installation.
   */
  public function installPackages(array $packages): void {
    try {
      $this->managePackages($packages, self::ACTION_REQUIRE);
    }
    catch (\Exception $e) {
      $this->logger->error('Error installing packages: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Removes a list of Composer packages.
   *
   * @param array $packages
   *   An array of package names to remove.
   *
   * @throws \Exception
   *   If there's an error during package removal.
   */
  public function removePackages(array $packages): void {
    try {
      $this->managePackages($packages, self::ACTION_REMOVE);
    }
    catch (\Exception $e) {
      $this->logger->error('Error removing packages: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Check if the package is already installed.
   *
   * @param string $package
   *   The package name.
   *
   * @return bool
   *   True if the package is installed, false otherwise.
   */
  public function checkPackage(string $package): bool {
    $command = ['composer', self::ACTION_SHOW, '--no-interaction', $package];
    $path = $this->getDrupalRootPath();
    return $this->runProcess($command, $path, $package);
  }

  /**
   * Manages the installation or removal of packages.
   *
   * This method handles the core logic for package management, including
   * validating paths, filtering packages, and processing each package.
   *
   * @param array $packages
   *   An array of package names.
   * @param string $action
   *   The action to perform, either to install or uninstall a package.
   *
   * @throws \Exception
   *   If there's an error during package management.
   */
  protected function managePackages(array $packages, string $action): void {
    $drupalRootPath = $this->getDrupalRootPath();
    if (!$this->validatePath($drupalRootPath)) {
      throw new \Exception("Invalid Drupal root path: {$drupalRootPath}");
    }

    $actionTitle = ucfirst($action) . 'ing packages...';
    $this->logMessage($this->t($actionTitle)->render(), 'info');

    if ($action === self::ACTION_REQUIRE) {
      $packages = $this->filterInstalledPackages($packages);
    }

    $this->saveSelectedPackages($packages);

    $progressBar = $this->initializeProgressBar(count($packages));

    $allSuccess = $this->processPackages($packages, $drupalRootPath, $action, $progressBar);

    $this->finishProgressBar($progressBar);

    if ($allSuccess) {
      $this->logMessage($this->t('Packages @action successfully.', ['@action' => $action . 'ed'])->render(), 'success');
    }
    else {
      $this->logMessage($this->t('Some packages failed to @action. Please check the logs for details.', ['@action' => $action])->render(), 'warning');
    }
  }

  /**
   * Filters out already installed packages.
   *
   * @param array $packages
   *   The list of packages to filter.
   *
   * @return array
   *   The filtered list of packages.
   */
  private function filterInstalledPackages(array $packages): array {
    return array_filter($packages, function ($package) {
      if (is_string($package) && $this->checkPackage($package)) {
        $this->logMessage($this->t('Package @package is already installed. Skipping installation.', ['@package' => $package])->render(), 'warning');
        return FALSE;
      }
      return TRUE;
    });
  }

  /**
   * Processes an individual package using Composer.
   *
   * @param string $package
   *   The package name.
   * @param string $path
   *   The path to the project directory.
   * @param string $action
   *   The action to perform, either 'require' or 'remove'.
   *
   * @return bool
   *   TRUE if the package was processed successfully, FALSE otherwise.
   */
  protected function processPackage(string $package, string $path, string $action): bool {
    if (!in_array($action, [self::ACTION_REQUIRE, self::ACTION_REMOVE, self::ACTION_SHOW])) {
      $this->logMessage($this->t("Invalid action '@action'.", ['@action' => $action])->render(), 'error');
      return FALSE;
    }

    $command = $this->buildComposerCommand($action, $package, $path);
    return $this->runProcess($command, $path, $package);
  }

  /**
   * Builds the Composer command array.
   *
   * @param string $action
   *   The action to perform (require, remove, show).
   * @param string $package
   *   The package name.
   * @param string $path
   *   The working directory path.
   *
   * @return array
   *   The Composer command array.
   */
  private function buildComposerCommand(string $action, string $package, string $path): array {
    return [
      'composer',
      $action,
      $package,
      "--working-dir={$path}",
      '--no-interaction',
      '--dev',
    ];
  }

  /**
   * Runs a process to execute the given command.
   *
   * @param array $command
   *   The command to run.
   * @param string $path
   *   The path where the command should be executed.
   * @param string $package
   *   The package being processed.
   *
   * @return bool
   *   TRUE if the process was successful, FALSE otherwise.
   */
  protected function runProcess(array $command, string $path, string $package): bool {
    $process = new Process($command, $path);
    $process->setTimeout($this->getProcessTimeout());

    try {
      $process->mustRun();

      if (in_array(self::ACTION_SHOW, $command)) {
        return TRUE;
      }

      $this->logSuccess($package);
      return TRUE;
    }
    catch (ProcessFailedException $exception) {
      if (in_array(self::ACTION_SHOW, $command)) {
        return FALSE;
      }

      $this->logError($package, $exception->getMessage());
      return FALSE;
    }
  }

  /**
   * Gets the process timeout from configuration or uses a default value.
   *
   * @return int
   *   The process timeout in seconds.
   */
  private function getProcessTimeout(): int {
    $config = $this->configFactory->get('clean_code.settings');
    return $config->get('process_timeout') ?? 300;
  }

  /**
   * Logs success messages.
   *
   * @param string $package
   *   The package name.
   */
  private function logSuccess(string $package): void {
    $this->logMessage($this->t('@package processed successfully.', ['@package' => $package])->render(), 'success');
  }

  /**
   * Logs error messages.
   *
   * @param string $package
   *   The package name.
   * @param string $message
   *   The error message.
   */
  private function logError(string $package, string $message): void {
    $this->logMessage($this->t('Failed to process @package: @message', [
      '@package' => $package,
      '@message' => $message,
    ])->render(), 'error');
  }

  /**
   * Gets the Drupal root path.
   *
   * @return string
   *   The Drupal root path.
   */
  public function getDrupalRootPath(): string {
    return $this->drupalRoot . '/..';
  }

  /**
   * Gets the module path for the clean_code module.
   *
   * @return string
   *   The module path.
   */
  public function getModulePath(): string {
    return $this->moduleHandler->getModule('clean_code')->getPath();
  }

  /**
   * Validates if the provided path exists.
   *
   * @param string $path
   *   The path to validate.
   *
   * @return bool
   *   TRUE if the path exists, FALSE otherwise.
   */
  private function validatePath(string $path): bool {
    if (!file_exists($path)) {
      $this->logMessage($this->t('Invalid path: @path', ['@path' => $path])->render(), 'error');
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Saves the selected packages to the configuration.
   *
   * @param array $packages
   *   An array of selected packages.
   */
  protected function saveSelectedPackages(array $packages): void {
    $config = $this->configFactory->getEditable('clean_code.settings');
    $config->set('selected_packages', $packages)->save();
    $this->logMessage($this->t('Selected packages saved to configuration.')->render(), 'success');
  }

  /**
   * Logs a message using either SymfonyStyle or Drupal's logger.
   *
   * @param string $message
   *   The message to log.
   * @param string $type
   *   The type of message (info, success, warning, error).
   */
  private function logMessage(string $message, string $type): void {
    if (isset($this->io)) {
      switch ($type) {
        case 'success':
          $this->io->success($message);
          break;

        case 'warning':
          $this->io->warning($message);
          break;

        case 'error':
          $this->io->error($message);
          break;

        default:
          $this->io->text($message);
      }
    }
    else {
      $this->logger->$type($message);
    }
  }

  /**
   * Initializes the progress bar for package processing.
   *
   * @param int $packageCount
   *   The number of packages to process.
   *
   * @return \Symfony\Component\Console\Helper\ProgressBar|null
   *   The initialized progress bar, or NULL if IO is not set.
   */
  private function initializeProgressBar(int $packageCount): ?ProgressBar {
    if (isset($this->io)) {
      $progressBar = new ProgressBar($this->io, $packageCount);
      $progressBar->setFormat('verbose');
      $progressBar->start();
      return $progressBar;
    }
    return NULL;
  }

  /**
   * Processes packages and updates the progress bar.
   *
   * @param array $packages
   *   The packages to process.
   * @param string $drupalRootPath
   *   The Drupal root path.
   * @param string $action
   *   The action to perform on the packages.
   * @param \Symfony\Component\Console\Helper\ProgressBar|null $progressBar
   *   The progress bar to update, or NULL if not using a progress bar.
   *
   * @return bool
   *   TRUE if all packages were processed successfully, FALSE otherwise.
   */
  private function processPackages(array $packages, string $drupalRootPath, string $action, ?ProgressBar $progressBar): bool {
    $allSuccess = TRUE;

    foreach ($packages as $package) {
      if (!is_string($package)) {
        $this->logMessage($this->t('Invalid package format. Expected string, got @type', ['@type' => gettype($package)])->render(), 'error');
        $allSuccess = FALSE;
        continue;
      }

      if (!$this->processPackage($package, $drupalRootPath, $action)) {
        $allSuccess = FALSE;
      }

      if ($progressBar) {
        $progressBar->advance();
      }
    }

    return $allSuccess;
  }

  /**
   * Finishes the progress bar if it exists.
   *
   * @param \Symfony\Component\Console\Helper\ProgressBar|null $progressBar
   *   The progress bar to finish, or NULL if not using a progress bar.
   */
  private function finishProgressBar(?ProgressBar $progressBar): void {
    if ($progressBar) {
      $progressBar->finish();
      $this->io->newLine();
    }
  }

}
