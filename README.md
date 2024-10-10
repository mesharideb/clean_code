
# Clean Code Module

## Overview

The Clean Code module integrates GrumPHP into Drupal project to enforce coding standards and perform code quality checks. It provides Drush commands to generate and manage GrumPHP configuration, ensuring your codebase stays clean and adheres to best practices.

## Features

- Automated code quality checks using GrumPHP.
- Drush commands for generating GrumPHP configuration.
- Composer package management for GrumPHP tasks.
- Integration with Git pre-commit hooks.
- Saves GrumPHP results to a log file.

## Installation

1. Add the module via Composer:
   ```bash
   composer require drupal/clean_code
   ```

2. Enable the module:
   ```bash
   drush en clean_code
   ```

## Usage

### Drush Commands

1. **Generate GrumPHP Configuration and Install Packages**:
   - Command: `drush clean_code:generate-install`
   - Alias: `drush ccgi`
   - Example:
     ```bash
     drush clean_code:generate-install
     ```

2. **Run GrumPHP Checks**:
   - Command: `drush clean_code:check`
   - Aliases: `drush clc:check`, `drush code:check`
   - Examples:
     ```bash
     drush clean_code:check
     drush clean_code:check --tasks=phpcs,phplint
     ```

3. **Generate GrumPHP Configuration Without Installing Packages (Override)**:
   - Command: `drush clean_code:generate-no-install`
   - Alias: `drush ccgni`
   - Example:
     ```bash
     drush clean_code:generate-no-install
     ```

### Available Tasks

GrumPHP tasks available for code checks:

- **git_blacklist**: Ensure no blacklisted files are committed.
- **git_branch_name**: Validate branch names.
- **git_commit_message**: Validate commit message format.
- **phpcs**: Enforce PHP coding standards (PHP CodeSniffer).
- **phplint**: Perform PHP lint checks for syntax errors.
- **phpmd**: Detect code smells using PHP Mess Detector.
- **phpstan**: Analyze PHP code with PHPStan.
- **phpunit**: Run PHPUnit tests.
- **twigcs**: Check Twig templates for coding standards.
- **yamllint**: Lint YAML files for syntax and style issues.
- **jsonlint**: Lint JSON files for syntax correctness.
- **security-checker**: Check project dependencies for security vulnerabilities.


### Troubleshooting

#### Fixers Not Working

This is how to fix it:

1. Navigate to the Git hooks directory:

```bash
   cd .git/hooks
   vim pre-commit
   add '< /dev/tty'
```

```bash
# Grumphp env vars
export GRUMPHP_GIT_WORKING_DIR="$(git rev-parse --show-toplevel)"

# Run GrumPHP
(cd "./" && printf "%s\n" "${DIFF}" | exec 'vendor/bin/grumphp' 'git:pre-commit' '--skip-success-output' < /dev/tty)
```


## Configuration

use `drush ccgi` to install the necessary packages and set up the GrumPHP configuration file:
```bash
drush ccgi
```
Use `drush ccgni` to edit the GrumPHP configuration file. This file allows you to configure which tasks to run, task-specific options, and more.
```bash
drush ccgni
```

## License

This module is licensed under the [GNU General Public License version 2 or later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html).
