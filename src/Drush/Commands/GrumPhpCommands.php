<?php

declare(strict_types=1);

namespace Drupal\clean_code\Drush\Commands;

use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Provides Drush commands for running GrumPHP checks.
 */
class GrumPhpCommands extends DrushCommands {

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The extension path resolver.
   *
   * @var \Drupal\Core\Extension\ExtensionPathResolver
   */
  protected ExtensionPathResolver $extensionPathResolver;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The Drupal root directory.
   *
   * @var string
   */
  private string $drupalRoot;

  /**
   * The stream wrapper manager service.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Constructs a new GrumPhpCommands object.
   *
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $extension_path_resolver
   *   The extension path resolver.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param string $drupal_root
   *   The Drupal root directory.
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager
   *   The stream wrapper manager service.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ExtensionPathResolver $extension_path_resolver,
    LoggerChannelFactoryInterface $logger_factory,
    string $drupal_root,
    StreamWrapperManagerInterface $stream_wrapper_manager,
  ) {
    parent::__construct();
    $this->fileSystem = $file_system;
    $this->extensionPathResolver = $extension_path_resolver;
    $this->loggerFactory = $logger_factory;
    $this->drupalRoot = $drupal_root;
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * Runs GrumPHP checks and saves the results to a file.
   *
   * @command clean_code:check
   * @aliases clc:check,code:check
   * @option tasks Comma-separated list of specific tasks to run.
   * @usage clean_code:check
   *   Run GrumPHP checks and save results to a file.
   * @usage clean_code:check --tasks=phpcs,phplint
   *   Run specific GrumPHP tasks (PHP CodeSniffer and PHP Lint in this
   *   example).
   *
   * @throws \Exception
   */
  public function runGrumPhp(array $options = ['tasks' => '']): void {
    $io = new SymfonyStyle($this->input(), $this->output());

    try {
      $grumphp_path = $this->getGrumPhpPath();
      if (!$grumphp_path) {
        throw new \RuntimeException('GrumPHP executable not found.');
      }

      $io->text('Running GrumPHP checks...');

      $command = [$grumphp_path, 'run', '--no-interaction'];

      if (!empty($options['tasks'])) {
        $tasks = explode(',', $options['tasks']);
        foreach ($tasks as $task) {
          $command[] = '--tasks=' . trim($task);
        }
      }

      $process = new Process($command);
      $process->setWorkingDirectory($this->drupalRoot);
      $process->setTimeout(300);
      $process->run();

      $output = $process->getOutput();
      $error_output = $process->getErrorOutput();

      $result = $output . PHP_EOL . $error_output;
      $file_path = $this->saveResultToFile($result);

      if ($process->isSuccessful()) {
        $io->success('GrumPHP checks passed. Results saved to: ' . $file_path);
      }
      else {
        $io->error('GrumPHP checks failed. Results saved to: ' . $file_path);
      }
    }
    catch (\Exception $e) {
      $io->error('An error occurred while running GrumPHP checks: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Gets the path to the GrumPHP executable.
   *
   * @return string|null
   *   The path to the GrumPHP executable, or NULL if not found.
   */
  protected function getGrumPhpPath(): ?string {
    $possible_paths = [
      $this->drupalRoot . '/../vendor/bin/grumphp',
      $this->drupalRoot . '/../vendor/phpro/grumphp/bin/grumphp',
    ];

    foreach ($possible_paths as $path) {
      if (file_exists($path)) {
        return $path;
      }
    }

    return NULL;
  }

  /**
   * Saves the GrumPHP results to a file.
   *
   * @param string $content
   *   The content to save.
   *
   * @return string
   *   The path to the saved file.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   */
  protected function saveResultToFile(string $content): string {
    $directory = 'public://grumphp_results';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $file_name = 'grumphp_result_' . date('Y-m-d_H-i-s') . '.txt';
    $file_path = $directory . '/' . $file_name;

    if (!$this->fileSystem->saveData($content, $file_path, FileSystemInterface::EXISTS_REPLACE)) {
      throw new \RuntimeException('Failed to save GrumPHP check results to file.');
    }

    return $this->streamWrapperManager->getViaUri($file_path)->getExternalUrl();
  }

}
