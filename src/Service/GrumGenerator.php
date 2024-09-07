<?php

declare(strict_types=1);

namespace Drupal\clean_code\Service;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides functionality to generate GrumPHP configuration files.
 */
class GrumGenerator {

  /**
   * The SymfonyStyle IO object for user interaction.
   *
   * @var \Symfony\Component\Console\Style\SymfonyStyle
   */
  private SymfonyStyle $io;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * GrumGenerator constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(FileSystemInterface $fileSystem) {
    $this->fileSystem = $fileSystem;
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
   * Generates a GrumPHP configuration file.
   *
   * @param string $project_root
   *   The project root directory.
   * @param array $selected_packages
   *   The selected packages for which to generate configuration.
   */
  public function generateGrumFile(string $project_root, array $selected_packages): void {
    $this->io->section('Generating GrumPHP configuration file');

    $grumphp_file = '/grumphp.yml';

    if (file_exists($project_root . $grumphp_file)) {
      $this->handleExistingConfigFile($project_root . $grumphp_file);
    }

    // Initialize the base configuration.
    $config = $this->initializeBaseConfig();

    // Task-specific configurations based on selected packages.
    foreach ($selected_packages as $task) {
      $this->configureTask($task, $config);
    }

    $this->finalizeConfig($project_root, $grumphp_file, $config);
  }

  /**
   * Handle an existing GrumPHP configuration file.
   *
   * @param string $grumphp_file
   *   The path to the existing configuration file.
   */
  private function handleExistingConfigFile(string $grumphp_file): void {
    $this->io->warning('GrumPHP configuration file already exists.');
    if ($this->io->confirm('Do you want to overwrite the existing file?', FALSE)) {
      $this->fileSystem->delete($grumphp_file);
    }
    else {
      $this->io->warning('GrumPHP configuration file generation aborted.');
      throw new \RuntimeException('GrumPHP configuration file generation aborted by user.');
    }
  }

  /**
   * Initialize the base GrumPHP configuration.
   *
   * @return array
   *   The base GrumPHP configuration array.
   */
  private function initializeBaseConfig(): array {
    return [
      'grumphp' => [
        'hide_circumvention_tip' => $this->io->confirm('Hide circumvention tip?', TRUE),
        'process_timeout' => (int) $this->io->ask('Process timeout (in seconds)?', '170'),
        'stop_on_failure' => $this->io->confirm('Stop on failure?', FALSE),
        'ignore_unstaged_changes' => $this->io->confirm('Ignore unstaged changes?', FALSE),
        'ascii' => [
          'failed' => NULL,
          'succeeded' => NULL,
        ],
        'tasks' => [],
      ],
    ];
  }

  /**
   * Configure a specific task in the GrumPHP configuration.
   *
   * @param string $task
   *   The task to configure.
   * @param array &$config
   *   The GrumPHP configuration array to modify.
   */
  private function configureTask(string $task, array &$config): void {
    $this->io->section("Configuring task: $task");

    switch ($task) {
      case 'git_blacklist':
        $config['grumphp']['tasks']['git_blacklist'] = $this->configureGitBlacklist();
        break;

      case 'git_branch_name':
        $config['grumphp']['tasks']['git_branch_name'] = $this->configureGitBranchName();
        break;

      case 'git_commit_message':
        $config['grumphp']['tasks']['git_commit_message'] = $this->configureGitCommitMessage();
        break;

      case 'phplint':
        $config['grumphp']['tasks']['phplint'] = $this->configurePhplint();
        break;

      case 'phpcs':
        $config['grumphp']['tasks']['phpcs'] = $this->configurePhpcs();
        break;

      case 'phpmd':
        $config['grumphp']['tasks']['phpmd'] = $this->configurePhpmd();
        break;

      case 'phpstan':
        $config['grumphp']['tasks']['phpstan'] = $this->configurePhpstan();
        break;

      case 'phpunit':
        $config['grumphp']['tasks']['phpunit'] = $this->configurePhpunit();
        break;

      case 'twigcs':
        $config['grumphp']['tasks']['twigcs'] = $this->configureTwigcs();
        break;

      case 'yamllint':
        $config['grumphp']['tasks']['yamllint'] = $this->configureYamllint();
        break;

      case 'jsonlint':
        $config['grumphp']['tasks']['jsonlint'] = $this->configureJsonlint();
        break;

      case 'security-checker':
        $config['grumphp']['tasks']['securitychecker_enlightn'] = $this->configureSecurityCheckerEnlightn();
        break;

      default:
        $this->io->warning("Task configuration for $task not implemented.");
        break;
    }
  }

  /**
   * Finalize and save the GrumPHP configuration.
   *
   * @param string $projectRoot
   *   The project root directory.
   * @param string $grumphpFile
   *   The path to the GrumPHP configuration file.
   * @param array $config
   *   The GrumPHP configuration array.
   */
  private function finalizeConfig(string $projectRoot, string $grumphpFile, array $config): void {
    $this->io->text('Finalizing GrumPHP configuration...');

    // Convert the configuration array to YAML format.
    $yaml = Yaml::dump($config, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

    // Ensure the directory exists and is writable.
    $directory = dirname($projectRoot . $grumphpFile);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    try {
      $result = $this->fileSystem->saveData($yaml, $projectRoot . $grumphpFile, FileSystemInterface::EXISTS_REPLACE);
    }
    catch (\Exception $e) {
      $this->io->error('Failed to save the GrumPHP configuration file: ' . $e->getMessage());
    }

    if ($result) {
      $this->io->progressStart(100);
      $this->io->newLine();
      $this->io->success('GrumPHP configuration file generated.');
    }
    else {
      $this->io->error('Failed to save the GrumPHP configuration file.');
    }
  }

  /**
   * Configures the git_blacklist task.
   *
   * @return array
   *   The configuration array for the git_blacklist task.
   */
  private function configureGitBlacklist(): array {
    return [
      'keywords' => $this->askKeywords(),
      'whitelist_patterns' => [],
      'triggered_by' => $this->askTriggeredBy(['php', 'module', 'theme', 'inc', 'phtml', 'php3', 'php4', 'php5']),
      'regexp_type' => 'G',
      'match_word' => FALSE,
      'ignore_patterns' => [],
    ];
  }

  /**
   * Configures the git_branch_name task.
   *
   * @return array
   *   The configuration array for the git_branch_name task.
   */
  private function configureGitBranchName(): array {
    return [
      'whitelist' => $this->askWhitelist([
        '/^(feature|bugfix|hotfix|release|support|task|chore|improvement|refactor)\\/([a-z0-9\\-]+)$/',
      ]),
      'blacklist' => ['master', 'develop', 'production', 'staging'],
      'allow_detached_head' => FALSE,
    ];
  }

  /**
   * Configures the git_commit_message task.
   *
   * @return array
   *   The configuration array for the git_commit_message task.
   */
  private function configureGitCommitMessage(): array {
    return [
      'allow_empty_message' => FALSE,
      'enforce_capitalized_subject' => TRUE,
      'enforce_no_subject_punctuations' => TRUE,
      'enforce_no_subject_trailing_period' => TRUE,
      'enforce_single_lined_subject' => TRUE,
      'max_body_width' => 72,
      'max_subject_width' => 60,
      'matchers' => $this->askMatchers(),
      'case_insensitive' => FALSE,
      'multiline' => TRUE,
      'skip_on_merge_commit' => TRUE,
    ];
  }

  /**
   * Configures the phplint task.
   *
   * @return array
   *   The configuration array for the phplint task.
   */
  private function configurePhplint(): array {
    return [
      'exclude' => [],
      'jobs' => (int) $this->io->ask('Number of parallel jobs?', '4'),
      'short_open_tag' => $this->io->confirm('Allow short open tag?', TRUE),
      'ignore_patterns' => $this->askWhitelist([
        '#web/modules/custom#',
        '#web/themes/custom#',
      ]),
      'triggered_by' => $this->askTriggeredBy([
        'php',
        'module',
        'theme',
        'inc',
        'phtml',
        'php3',
        'php4',
        'php5',
      ]),
    ];
  }

  /**
   * Configures the phpcs task.
   *
   * @return array
   *   The configuration array for the phpcs task.
   */
  private function configurePhpcs(): array {
    return [
      'standard' => explode(',', $this->io->ask('PHP CodeSniffer standard(s) to use? (comma separated)', 'Drupal,DrupalPractice')),
      'severity' => (int) $this->io->ask('Severity level?', '5'),
      'error_severity' => (int) $this->io->ask('Error severity level?', '5'),
      'warning_severity' => (int) $this->io->ask('Warning severity level?', '5'),
      'tab_width' => NULL,
      'report' => $this->io->ask('Report format?', 'full'),
      'report_width' => (int) $this->io->ask('Report width?', '80'),
      'whitelist_patterns' => $this->askWhitelist([
        '#web/modules/custom#',
        '#web/themes/custom#',
      ]),
      'encoding' => $this->io->ask('File encoding?', 'utf-8'),
      'ignore_patterns' => [],
      'sniffs' => [],
      'triggered_by' => ['php'],
      'exclude' => [],
      'show_sniffs_error_path' => TRUE,
    ];
  }

  /**
   * Configures the phpmd task.
   *
   * @return array
   *   The configuration array for the phpmd task.
   */
  private function configurePhpmd(): array {
    return [
      'whitelist_patterns' => $this->askWhitelist([
        '#web/modules/custom#',
        '#web/themes/custom#',
      ]),
      'exclude' => [],
      'report_format' => $this->io->ask('Report format?', 'text'),
      'ruleset' => explode(',', $this->io->ask('Rulesets to apply? (comma separated)', 'cleancode,codesize,naming,controversial,design,unusedcode')),
      'triggered_by' => ['php', 'module', 'theme'],
    ];
  }

  /**
   * Configures the phpstan task.
   *
   * @return array
   *   The configuration array for the phpstan task.
   */
  private function configurePhpstan(): array {
    return [
      'autoload_file' => NULL,
      'level' => (int) $this->io->ask('Level of rule strictness?', '7'),
      'force_patterns' => [],
      'ignore_patterns' => explode(',', $this->io->ask('Ignore patterns? (comma separated)', '.github,.gitlab,/config,/drush,/web/robots.txt,/web/sites/default,bower_components,node_modules,/vendor')),
      'triggered_by' => ['php', 'module', 'theme', 'inc'],
      'memory_limit' => $this->io->ask('Memory limit?', "-1"),
      'use_grumphp_paths' => TRUE,
    ];
  }

  /**
   * Configures the phpunit task.
   *
   * @return array
   *   The configuration array for the phpunit task.
   */
  private function configurePhpunit(): array {
    return [
      'config_file' => $this->io->ask('Path to PHPUnit config file?', 'phpunit.xml.dist'),
      'testsuite' => NULL,
      'group' => [],
      'exclude_group' => [],
      'always_execute' => FALSE,
      'order' => NULL,
    ];
  }

  /**
   * Configures the twigcs task.
   *
   * @return array
   *   The configuration array for the twigcs task.
   */
  private function configureTwigcs(): array {
    return [
      'path' => $this->io->ask('Path to twig files?', '.'),
      'severity' => $this->io->ask('Severity level?', 'warning'),
      'display' => $this->io->ask('Violations to display (all/blocking)?', 'all'),
      'ruleset' => $this->io->ask('Ruleset to use?', 'FriendsOfTwig\Twigcs\Ruleset\Official'),
      'triggered_by' => ['twig'],
      'exclude' => [],
    ];
  }

  /**
   * Configures the yamllint task.
   *
   * @return array
   *   The configuration array for the yamllint task.
   */
  private function configureYamllint(): array {
    return [
      'whitelist_patterns' => $this->askWhitelist([
        '#web/modules/custom#',
        '#web/themes/custom#',
      ]),
      'ignore_patterns' => [],
      'object_support' => $this->io->confirm('Enable object support?', FALSE),
      'exception_on_invalid_type' => $this->io->confirm('Throw exception on invalid type?', FALSE),
      'parse_constant' => $this->io->confirm('Enable parsing constants?', FALSE),
      'parse_custom_tags' => $this->io->confirm('Enable custom tags?', FALSE),
    ];
  }

  /**
   * Configures the jsonlint task.
   *
   * @return array
   *   The configuration array for the jsonlint task.
   */
  private function configureJsonlint(): array {
    return [
      'ignore_patterns' => [],
      'detect_key_conflicts' => $this->io->confirm('Detect key conflicts?', FALSE),
    ];
  }

  /**
   * Configures the securitychecker_enlightn task.
   *
   * @return array
   *   The configuration array for the securitychecker_enlightn task.
   */
  private function configureSecurityCheckerEnlightn(): array {
    return [
      'lockfile' => $this->io->ask('Path to composer.lock file?', 'composer.lock'),
      'run_always' => $this->io->confirm('Run always?', FALSE),
    ];
  }

  /**
   * Helper method to ask for keywords.
   *
   * @return array
   *   The keywords as an array.
   */
  private function askKeywords(): array {
    return explode(',', $this->io->ask('Enter keywords (comma separated)', 'die(,var_dump(),exit;,dd(,kint(,print_r(,debug('));
  }

  /**
   * Helper method to ask for triggered_by files.
   *
   * @param array $default
   *   The default files to trigger the task.
   *
   * @return array
   *   The triggered_by files as an array.
   */
  private function askTriggeredBy(array $default): array {
    return explode(',', $this->io->ask('Files to trigger this task? (comma separated)', implode(',', $default)));
  }

  /**
   * Helper method to ask for whitelist patterns.
   *
   * @param array $default
   *   The default whitelist patterns.
   *
   * @return array
   *   The whitelist patterns as an array.
   */
  private function askWhitelist(array $default): array {
    return explode(',', $this->io->ask('Enter whitelist patterns (comma separated)', implode(',', $default)));
  }

  /**
   * Helper method to ask for matchers.
   *
   * @return array
   *   The matchers as an array.
   */
  private function askMatchers(): array {
    return explode(',', $this->io->ask('Enter matchers (comma separated)', '^(feat|fix|refactor|style|test|docs|build|ops|chore)\\([a-z0-9\\-]+\\): [a-z0-9\\- ]+\\(JIRA-\\d+\\),/^Merge branch \'.+\'$/,/^Revert "[^"]+"$/'));
  }

}
