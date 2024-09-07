<?php

declare(strict_types=1);

namespace Drupal\clean_code\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Provides functionality to manage Composer packages.
 */
class PackageManager {

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
   * @var \Symfony\Component\Console\Style\SymfonyStyle
   */
  private SymfonyStyle $io;

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
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    FileSystemInterface $file_system,
    ModuleHandlerInterface $module_handler,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->logger = $logger_factory->get('clean_code');
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $configFactory;
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
   */
  public function installPackages(array $packages): void {
    $this->managePackages($packages, 'require');
  }

  /**
   * Removes a list of Composer packages.
   *
   * @param array $packages
   *   An array of package names to remove.
   */
  public function removePackages(array $packages): void {
    $this->managePackages($packages, 'remove');
  }

  /**
   * Manages the installation or removal of packages.
   *
   * @param array $packages
   *   An array of package names.
   * @param string $action
   *   The action to perform, either 'require' to install or 'remove' to uninstall.
   */
  protected function managePackages(array $packages, string $action): void {
    $drupalRootPath = $this->getDrupalRootPath();
    if (!$this->validatePath($drupalRootPath)) {
      return;
    }

    $actionTitle = ucfirst($action) . 'ing packages...';
    if (isset($this->io)) {
      $this->io->title($actionTitle);
      $this->io->section("Preparing to {$action} the following packages:");
      $this->io->listing($packages);
      $this->io->newLine();
    }
    else {
      $this->logger->info($actionTitle);
    }

    $this->saveSelectedPackages($packages);

    if (isset($this->io)) {
      $spinner = new ProgressBar($this->io, count($packages));
      $spinner->setFormat('verbose');
      $spinner->start();
    }

    // Flag to track if all packages were processed successfully.
    $all_success = TRUE;

    foreach ($packages as $package) {
      if (!$this->processPackage($package, $drupalRootPath, $action)) {
        // Set flag to false if any package fails.
        $all_success = FALSE;
        if (isset($this->io)) {
          $this->io->error(sprintf('Failed to process package: %s', $package));
        }
        else {
          $this->logger->error(sprintf('Failed to process package: %s', $package));
        }
        // Continue to the next package if one fails.
        continue;
      }
      isset($spinner) ? $spinner->advance() : NULL;
    }

    isset($spinner) ? $spinner->finish() : NULL;
    isset($this->io) ? $this->io->newLine() : NULL;

    if ($all_success) {
      if (isset($this->io)) {
        $this->io->success(sprintf('Packages %sed successfully.', $action));
      }
      else {
        $this->logger->info(sprintf('Packages %sed successfully.', $action));
      }
    }
    else {
      if (isset($this->io)) {
        $this->io->warning(sprintf('Some packages failed to %s. Please check the logs for details.', $action));
      }
      else {
        $this->logger->warning(sprintf('Some packages failed to %s. Please check the logs for details.', $action));
      }
    }
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
    // Validate action.
    if (!in_array($action, ['require', 'remove'])) {
      if (isset($this->io)) {
        $this->io->error("Invalid action '{$action}'. Use 'require' or 'remove'.");
      }
      else {
        $this->logger->error("Invalid action '{$action}'. Use 'require' or 'remove'.");
      }
      return FALSE;
    }

    // Prepare the Composer command.
    $command = [
      'composer',
      $action,
      $package,
      "--working-dir={$path}",
      '--no-interaction',
      '--dev',
    ];

    // Run the command and handle the result.
    return $this->runProcess($command, $path, $package);
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
    $process->setTimeout(300);

    try {
      $process->mustRun();
      $this->logSuccess($package);
      return TRUE;
    }
    catch (ProcessFailedException $exception) {
      $this->logError($package, $exception->getMessage());
      return FALSE;
    }
  }

  /**
   * Logs success messages.
   *
   * @param string $package
   *   The package name.
   */
  private function logSuccess(string $package): void {
    if (isset($this->io)) {
      $this->io->text("<info>{$package} processed successfully.</info>");
      $this->io->newLine();
    }
    else {
      $this->logger->info("{$package} processed successfully.");
    }
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
    if (isset($this->io)) {
      $this->io->error("Failed to process {$package}: " . $message);
    }
    $this->logger->error("Failed to process {$package}: " . $message);
  }

  /**
   * Gets the Drupal root path.
   *
   * @return string
   *   The Drupal root path.
   */
  public function getDrupalRootPath(): string {
    return \Drupal::root() . '/..';
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
      if (isset($this->io)) {
        $this->io->error("Invalid path: {$path}");
      }
      $this->logger->error("Invalid path: {$path}");
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

    if (isset($this->io)) {
      $this->io->success('Selected packages saved to configuration.');
    }
    else {
      $this->logger->info('Selected packages saved to configuration.');
    }
  }

}
