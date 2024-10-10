<?php

declare(strict_types=1);

namespace Drupal\clean_code\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Provides functionality to generate GrumPHP configuration files.
 */
class GrumGenerator {

  /**
   * The path to the module.
   *
   * @var string
   */
  private ?string $modulePath = NULL;

  /**
   * The path to the theme.
   *
   * @var string
   */
  private ?string $themePath = NULL;

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
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  private ModuleHandlerInterface $moduleHandler;

  /**
   * The theme handler service.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  private ThemeHandlerInterface $themeHandler;

  /**
   * GrumGenerator constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler service.
   */
  public function __construct(
    FileSystemInterface $fileSystem,
    ModuleHandlerInterface $moduleHandler,
    ThemeHandlerInterface $themeHandler,
  ) {
    $this->fileSystem = $fileSystem;
    $this->moduleHandler = $moduleHandler;
    $this->themeHandler = $themeHandler;
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
   * This method orchestrates the entire process of generating the GrumPHP
   * configuration file. It handles existing files, prompts for module and theme
   * paths, initializes the base configuration, configures tasks, and finalizes
   * the configuration.
   *
   * @param string $project_root
   *   The project root directory.
   * @param array $selected_packages
   *   The selected packages for which to generate configuration.
   *
   * @throws \RuntimeException
   *   If the file generation is aborted by the user or fails.
   */
  public function generateGrumFile(string $project_root, array $selected_packages): void {
    $this->io->section('Generating GrumPHP configuration file');

    $grumphp_file = '/grumphp.yml';

    try {
      if (file_exists($project_root . $grumphp_file)) {
        $this->handleExistingConfigFile($project_root . $grumphp_file);
      }

      // Prompt for and retrieve the module path.
      $module_name = $this->promptForExtension('module');
      if ($module_name !== NULL) {
        $this->modulePath = $this->getExtensionBasePath('module', $module_name);
      }

      // Prompt for and retrieve the theme path.
      $theme_name = $this->promptForExtension('theme');
      if ($theme_name !== NULL) {
        $this->themePath = $this->getExtensionBasePath('theme', $theme_name);
      }

      // Initialize the base configuration.
      $config = $this->initializeBaseConfig();

      // Task-specific configurations based on selected packages.
      foreach ($selected_packages as $task) {
        $this->configureTask($task, $config);
      }

      $this->finalizeConfig($project_root, $grumphp_file, $config);
    }
    catch (\Exception $e) {
      $this->io->error('An error occurred while generating the GrumPHP configuration: ' . $e->getMessage());
      throw new \RuntimeException('GrumPHP configuration file generation failed.', 0, $e);
    }
  }

  /**
   * Handle an existing GrumPHP configuration file.
   *
   * @param string $grumphp_file
   *   The path to the existing configuration file.
   *
   * @throws \RuntimeException
   *   If the user chooses not to overwrite the existing file.
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the file cannot be deleted.
   */
  private function handleExistingConfigFile(string $grumphp_file): void {
    $this->io->warning('GrumPHP configuration file already exists.');
    if ($this->io->confirm('Do you want to overwrite the existing file?', FALSE)) {
      try {
        $this->fileSystem->delete($grumphp_file);
      }
      catch (FileException $e) {
        throw new FileException('Failed to delete existing GrumPHP configuration file: ' . $e->getMessage());
      }
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
        'fixer' => [
          'enabled' => $this->io->confirm('Enable fixer?', FALSE),
        ],
        'ascii' => [
          'failed' => $this->getExtensionBasePath('module', 'clean_code') . '/ascii/failed.txt',
          'succeeded' => $this->getExtensionBasePath('module', 'clean_code') . '/ascii/succeeded.txt',
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
        $config['grumphp']['tasks']['twigcsfixer'] = $this->configureTwigcsFixer();
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
   * This method converts the configuration array to YAML format, ensures the
   * directory exists and is writable, and saves the configuration file.
   *
   * @param string $projectRoot
   *   The project root directory.
   * @param string $grumphpFile
   *   The path to the GrumPHP configuration file.
   * @param array $config
   *   The GrumPHP configuration array.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   If the file cannot be saved.
   */
  private function finalizeConfig(string $projectRoot, string $grumphpFile, array $config): void {
    $this->io->text('Finalizing GrumPHP configuration...');

    // Convert the configuration array to YAML format.
    $yaml = Yaml::dump($config, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

    // Ensure the directory exists and is writable.
    $directory = dirname($projectRoot . $grumphpFile);
    try {
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    }
    catch (FileException $e) {
      throw new FileException('Failed to prepare directory for GrumPHP configuration file: ' . $e->getMessage());
    }

    try {
      $result = $this->fileSystem->saveData($yaml, $projectRoot . $grumphpFile, FileSystemInterface::EXISTS_REPLACE);
      if (!$result) {
        throw new FileException('Failed to save the GrumPHP configuration file.');
      }
    }
    catch (FileException $e) {
      throw new FileException('Failed to save the GrumPHP configuration file: ' . $e->getMessage());
    }

    $this->io->progressStart(100);
    $this->io->newLine();
    $this->io->success('GrumPHP configuration file generated successfully.');
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
      'whitelist_patterns' => $this->askWhitelist([
        "#{$this->modulePath}#",
        "#{$this->themePath}#",
      ]),
      'triggered_by' => $this->askTriggeredBy(['php', 'module', 'theme', 'inc', 'phtml', 'php3', 'php4', 'php5']),
      'regexp_type' => 'G',
      'match_word' => FALSE,
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
      ]),
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
      'enforce_capitalized_subject' => FALSE,
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
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
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
        "#{$this->modulePath}#",
        "#{$this->themePath}#",
      ]),
      'encoding' => $this->io->ask('File encoding?', 'utf-8'),
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
      ]),
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
        "#{$this->modulePath}#",
        "#{$this->themePath}#",
      ]),
      'exclude' => [],
      'report_format' => $this->io->ask('Report format?', 'text'),
      'ruleset' => explode(',', $this->io->ask('Rulesets to apply? (comma separated)', 'cleancode,codesize,naming,controversial,design,unusedcode')),
      'triggered_by' => $this->askTriggeredBy(['php', 'module', 'theme', 'inc']),
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
      'level' => (int) $this->io->ask('Level of rule strictness?', '4'),
      'force_patterns' => [],
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
        '.github',
        '.gitlab',
        '/config',
        '/drush',
        '/web/robots.txt',
        '/web/sites/default',
        'bower_components',
        'node_modules',
        '/vendor',
      ]),
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
      'exclude' => $this->askWhitelist([
        '#web/core#',
        '#web/modules/contrib#',
        '#web/themes/contrib#',
        '#web/libraries#',
      ]),
    ];
  }

  /**
   * Configures the twigcsfixer task.
   *
   * @return array
   *   The configuration array for the twigcsfixer task.
   */
  private function configureTwigcsFixer(): array {
    return [
      'paths' => [],
      'level' => NULL,
      'config' => NULL,
      'report' => 'text',
      'no-cache' => TRUE,
      'verbose' => FALSE,
      'triggered_by' => ['twig'],
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
        "#{$this->modulePath}#",
        "#{$this->themePath}#",
      ]),
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
      ]),
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
      'ignore_patterns' => $this->askIgnoreList([
        "#{$this->modulePath}/tests#",
      ]),
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
    $input = $this->io->ask('Enter keywords (comma separated)', 'die(,var_dump(),exit;,dd(,kint(,print_r(,debug(');
    return array_map('trim', explode(',', $input));
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
    $input = $this->io->ask('Files to trigger this task? (comma separated)', implode(',', $default));
    return array_map('trim', explode(',', $input));
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
    $input = $this->io->ask('Enter whitelist patterns (comma separated)', implode(',', $default));
    return array_map('trim', explode(',', $input));
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
  private function askIgnoreList(array $default): array {
    $input = $this->io->ask('Enter ignore patterns (comma separated)', implode(',', $default));
    return array_map('trim', explode(',', $input));
  }

  /**
   * Helper method to ask for matchers.
   *
   * @return array
   *   The matchers as an array.
   */
  private function askMatchers(): array {
    $choices = [
      "default" => "<type>(<scope>): <subject> (JIRA-<issue>) || Merge branch <branch-name> || Revert <commit-message>",
      "custom" => "Type custom matchers",
      "none" => "None",
    ];

    $input = new ChoiceQuestion('Please select the matchers you want to include:', $choices, 'none');
    $input->setMultiselect(FALSE);

    $userInput = $this->io->askQuestion($input);

    if ($userInput === 'custom') {
      $customMatchers = $this->io->ask('Enter custom matchers (comma separated)', 'custom');
      return array_map('trim', explode(',', $customMatchers));
    }

    return $userInput === 'default' ? ['/^(feat|fix|refactor|style|test|docs|build|ops|chore)\\([a-z0-9_-]+\\): [a-zA-Z0-9 _()\\-]+$|^Merge branch .+$|^Revert \\\"[^\\\"]+\\\"$/'] : [];
  }

  /**
   * Prompts the user for a valid extension name and retrieves its path.
   *
   * @param string $type
   *   The type of extension ('module' or 'theme').
   * @param string $default
   *   The default name to use if the user provides no input.
   *
   * @return string|null
   *   The machine name of the valid extension, or null if skipped.
   */
  private function promptForExtension(string $type, ?string $default = NULL): ?string {
    while (TRUE) {
      $name = $this->io->ask("Enter your custom {$type} name, leave empty to skip", $default);
      // Allow skipping by entering an empty string.
      if (empty($name)) {
        $this->io->note("Skipping {$type} configuration.");
        return NULL;
      }

      if ($this->isValidExtension($type, $name)) {
        return $name;
      }

      $this->io->error("The {$type} '{$name}' does not exist.");
    }
  }

  /**
   * Checks if the provided extension exists.
   *
   * @param string $type
   *   The type of extension ('module' or 'theme').
   * @param string $machine_name
   *   The machine name of the extension.
   *
   * @return bool
   *   TRUE if the extension exists, FALSE otherwise.
   */
  private function isValidExtension(string $type, string $machine_name): bool {
    if ($type === 'module') {
      return $this->moduleHandler->moduleExists($machine_name);
    }
    if ($type === 'theme') {
      return $this->themeHandler->themeExists($machine_name);
    }
    return FALSE;
  }

  /**
   * Retrieves the base path of a Drupal module or theme.
   *
   * @param string $type
   *   The type of extension ('module' or 'theme').
   * @param string $machine_name
   *   The machine name of the extension.
   *
   * @return string
   *   The relative path starting from 'web/' or the absolute path if 'web/' is not found.
   *   Returns '~' if the extension type is invalid.
   */
  private function getExtensionBasePath(string $type, string $machine_name): string {
    // Select the appropriate handler based on type.
    $extension = ($type === 'module')
      ? $this->moduleHandler->getModule($machine_name)
      : $this->themeHandler->getTheme($machine_name);

    // Resolve the absolute path and normalize directory separators.
    $absolute_path = str_replace('\\', '/', $this->fileSystem->realpath($extension->getPath()));

    if (!$absolute_path) {
      $this->io->error("Could not resolve the path for '{$machine_name}'.");
      return '~';
    }

    // Locate the position of 'web/' in the path.
    $web_pos = strpos($absolute_path, '/web/');
    if ($web_pos !== FALSE) {
      // Extract the path starting from 'web/' onward.
      // +1 to exclude the leading '/'.
      return substr($absolute_path, $web_pos + 1);
    }

    // If 'web/' is not found, return the absolute path.
    return $absolute_path;
  }

}
