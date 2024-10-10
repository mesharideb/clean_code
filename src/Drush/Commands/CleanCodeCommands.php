<?php

declare(strict_types=1);

namespace Drupal\clean_code\Drush\Commands;

use Drupal\Core\State\StateInterface;
use Drupal\clean_code\Service\GrumGenerator;
use Drupal\clean_code\Service\PackageManager;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Provides Drush commands for managing Clean Code configurations.
 */
class CleanCodeCommands extends DrushCommands {

  /**
   * The package manager service.
   *
   * @var \Drupal\clean_code\Service\PackageManager
   */
  protected PackageManager $packageManager;

  /**
   * The GrumGenerator service.
   *
   * @var \Drupal\clean_code\Service\GrumGenerator
   */
  protected GrumGenerator $grumGenerator;

  /**
   * The State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a new CleanCodeCommands object.
   *
   * @param \Drupal\clean_code\Service\PackageManager $packageManager
   *   The package manager service.
   * @param \Drupal\clean_code\Service\GrumGenerator $grumGenerator
   *   The Clean Code generator service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(PackageManager $packageManager, GrumGenerator $grumGenerator, StateInterface $state) {
    parent::__construct();
    $this->packageManager = $packageManager;
    $this->grumGenerator = $grumGenerator;
    $this->state = $state;
  }

  /**
   * Generates Clean Code configuration and installs required packages.
   *
   * @command clean_code:generate-install
   * @aliases ccgi
   * @description Generate Clean Code configuration and install required packages.
   * @usage drush clean_code:generate-install
   *   Generates the Clean Code configuration file and installs the required Composer packages.
   *
   * @throws \Exception
   *   If there's an error during the generation or installation process.
   */
  public function generate(): void {
    try {
      $root = $this->packageManager->getDrupalRootPath();
      $this->packageManager->setIo(new SymfonyStyle($this->input(), $this->output()));
      $tasks = $this->getTasksList();
      $selectedTasks = $this->askUserForTasks($tasks);
      $selectedKeys = $this->getSelectedTaskKeys($selectedTasks, $tasks);

      // Remove Git-related tasks.
      $filteredTasks = array_diff($selectedKeys, ['git_blacklist', 'git_branch_name', 'git_commit_message', 'yamllint']);

      $this->installPackages($filteredTasks);

      $removePackages = $this->io()->confirm('Do you want the packages to be removed when the module is uninstalled?', FALSE);

      // Store the user preference in the State API.
      $this->state->set('clean_code.remove_packages', $removePackages);

      $this->grumGenerator->setIo(new SymfonyStyle($this->input(), $this->output()));
      $this->grumGenerator->generateGrumFile($root, $selectedKeys);

      $this->output()->writeln('<info>Clean Code configuration generated and packages installed successfully.</info>');
    }
    catch (\Exception $e) {
      $this->logger()->error('An error occurred during Clean Code configuration generation: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Generates Clean Code configuration without installing packages.
   *
   * @command clean_code:generate-no-install
   * @aliases ccgni
   * @description Generate Clean Code configuration without installing Composer packages.
   * @usage drush clean_code:generate-no-install
   *   Generates the Clean Code configuration file without installing the Composer
   *   packages.
   *
   * @throws \Exception
   *   If there's an error during the generation process.
   */
  public function generateNoInstall(): void {
    try {
      $root = $this->packageManager->getDrupalRootPath();
      $this->packageManager->setIo(new SymfonyStyle($this->input(), $this->output()));

      $tasks = $this->getTasksList();
      $selectedTasks = $this->askUserForTasks($tasks);
      $selectedKeys = $this->getSelectedTaskKeys($selectedTasks, $tasks);

      $this->grumGenerator->setIo(new SymfonyStyle($this->input(), $this->output()));
      $this->grumGenerator->generateGrumFile($root, $selectedKeys);

      $this->output()->writeln('<info>Clean Code configuration generated successfully.</info>');
    }
    catch (\Exception $e) {
      $this->logger()->error('An error occurred during Clean Code configuration generation: ' . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Installs selected Composer packages.
   *
   * @param array $selectedKeys
   *   The selected task keys.
   */
  private function installPackages(array $selectedKeys): void {
    $composerPackages = $this->getComposerPackages($selectedKeys);

    if (!empty($composerPackages)) {
      $this->output()->writeln('<info>Installing Composer packages...</info>');
      $this->packageManager->installPackages($composerPackages);
    }
  }

  /**
   * Retrieves the list of available tasks.
   *
   * @return array
   *   An associative array of task keys and their descriptions.
   */
  private function getTasksList(): array {
    return [
      'git_blacklist' => 'Git Blacklist',
      'git_branch_name' => 'Git Branch Name',
      'git_commit_message' => 'Git Commit Message',
      'phpcs' => 'PHP CodeSniffer',
      'phplint' => 'PHP Lint',
      'phpmd' => 'PHP Mess Detector',
      'phpstan' => 'PHPStan',
      'phpunit' => 'PHPUnit',
      'twigcs' => 'Twig CodeSniffer',
      'yamllint' => 'YAML Lint',
      'jsonlint' => 'JSON Lint',
      'security-checker' => 'Security Checker',
    ];
  }

  /**
   * Prompts the user to select tasks.
   *
   * @param array $tasks
   *   The array of available tasks.
   *
   * @return array
   *   The array of selected tasks.
   */
  private function askUserForTasks(array $tasks): array {
    $question = new ChoiceQuestion(
      'Please select the tasks you want to include (comma-separated):',
      array_values($tasks),
      0
    );
    $question->setMultiselect(TRUE);
    return $this->io()->askQuestion($question);
  }

  /**
   * Gets the selected task keys.
   *
   * @param array $selectedTasks
   *   The selected tasks.
   * @param array $tasks
   *   The array of all available tasks.
   *
   * @return array
   *   The array of selected task keys.
   */
  private function getSelectedTaskKeys(array $selectedTasks, array $tasks): array {
    return array_map(fn($task) => array_search($task, $tasks), $selectedTasks);
  }

  /**
   * Gets the Composer packages for the selected tasks.
   *
   * @param array $tasks
   *   The array of selected tasks.
   *
   * @return array
   *   The array of Composer packages to install.
   */
  private function getComposerPackages(array $tasks): array {
    $packages = [
      'phpcs' => 'drupal/coder',
      'phplint' => 'php-parallel-lint/php-parallel-lint',
      'phpmd' => 'phpmd/phpmd',
      'phpstan' => 'phpstan/phpstan',
      'phpunit' => 'phpunit/phpunit',
      'twigcs' => ['friendsoftwig/twigcs', 'vincentlanglet/twig-cs-fixer'],
      'jsonlint' => 'seld/jsonlint',
      'security-checker' => 'enlightn/security-checker',
    ];

    $selectedPackages = array_intersect_key($packages, array_flip($tasks));

    // Flatten the array to ensure all elements are strings.
    return array_reduce($selectedPackages, function ($carry, $item) {
      return is_array($item) ? array_merge($carry, $item) : array_merge($carry, [$item]);
    }, []);
  }

}
