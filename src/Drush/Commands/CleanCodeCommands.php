<?php

declare(strict_types=1);

namespace Drupal\clean_code\Drush\Commands;

use Drupal\clean_code\Service\GrumGenerator;
use Drupal\clean_code\Service\PackageManager;
use Drupal\Core\State\StateInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A Drush command file for managing GrumPHP configurations.
 */
class CleanCodeCommands extends DrushCommands {

  /**
   * The package manager service.
   *
   * @var \Drupal\clean_code\Service\PackageManager
   */
  protected $packageManager;

  /**
   * The GrumGenerator service.
   *
   * @var \Drupal\clean_code\Service\GrumGenerator
   */
  protected $grumGenerator;

  /**
   * The State service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor to inject services.
   *
   * @param \Drupal\clean_code\Service\PackageManager $packageManager
   *   The package manager service.
   * @param \Drupal\clean_code\Service\GrumGenerator $grumGenerator
   *   The GrumPHP generator service.
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
   * Generate GrumPHP configuration and install required packages.
   *
   * @command grumphp:generate-install
   * @aliases ggi
   * @description Generate GrumPHP configuration and install required packages.
   */
  public function generate(): void {
    $root = $this->packageManager->getDrupalRootPath();
    $this->packageManager->setIo(new SymfonyStyle($this->input(), $this->output()));
    $tasks = $this->getTasksList();
    $selectedTasks = $this->askUserForTasks($tasks);
    $selectedKeys = $this->getSelectedTaskKeys($selectedTasks, $tasks);

    // Remove Git-related tasks.
    $filteredTasks = array_diff($selectedKeys, ['git_blacklist', 'git_branch_name', 'git_commit_message', 'yamllint']);

    $this->installPackages($filteredTasks);

    $removePackages = $this->io()->confirm('Do you want the packages to be removed when the module is uninstalled?', FALSE);

    // Store the user preference in the State API using DI.
    $this->state->set('clean_code.remove_packages', $removePackages);

    $this->grumGenerator->setIo(new SymfonyStyle($this->input(), $this->output()));
    $this->grumGenerator->generateGrumFile($root, $selectedKeys);

    $this->output()->writeln('<info>GrumPHP configuration generated and packages installed successfully.</info>');
  }

  /**
   * Generate GrumPHP configuration without installing packages.
   *
   * @command grumphp:generate-no-install
   * @aliases ggni
   * @description Generate GrumPHP configuration without installing packages.
   */
  public function generateNoInstall(): void {
    $root = $this->packageManager->getDrupalRootPath();
    $this->packageManager->setIo(new SymfonyStyle($this->input(), $this->output()));

    $tasks = $this->getTasksList();
    $selectedTasks = $this->askUserForTasks($tasks);
    $selectedKeys = $this->getSelectedTaskKeys($selectedTasks, $tasks);

    $this->grumGenerator->setIo(new SymfonyStyle($this->input(), $this->output()));
    $this->grumGenerator->generateGrumFile($root, $selectedKeys);

    $this->output()->writeln('<info>GrumPHP configuration generated successfully.</info>');
  }

  /**
   * Install selected Composer packages.
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
   * Retrieves the list of tasks.
   *
   * @return string[]
   *   The list of tasks.
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
   * Asks the user for tasks.
   *
   * @param array $tasks
   *   The array of tasks.
   *
   * @return string[]
   *   The array of tasks after user input.
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
   * Get the selected task keys.
   *
   * @param array $selectedTasks
   *   The selected tasks.
   * @param array $tasks
   *   The array of tasks.
   *
   * @return string[]
   *   The array of selected task keys.
   */
  private function getSelectedTaskKeys(array $selectedTasks, array $tasks): array {
    return array_map(fn($task) => array_search($task, $tasks), $selectedTasks);
  }

  /**
   * Get the Composer packages for the selected tasks.
   *
   * @param array $tasks
   *   The array of selected tasks.
   *
   * @return array
   *   The array of Composer packages.
   */
  private function getComposerPackages(array $tasks): array {
    $packages = [
      'phpcs' => 'drupal/coder',
      'phplint' => 'php-parallel-lint/php-parallel-lint',
      'phpmd' => 'phpmd/phpmd',
      'phpstan' => 'phpstan/phpstan',
      'phpunit' => 'phpunit/phpunit',
      'twigcs' => 'friendsoftwig/twigcs',
      'jsonlint' => 'seld/jsonlint',
      'security-checker' => 'enlightn/security-checker',
    ];

    return array_intersect_key($packages, array_flip($tasks));
  }

}
