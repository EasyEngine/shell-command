Feature: ee shell argument validation
  Validation paths of `ee shell` that need no site of this suite. Unknown sites never reach Docker.

  # A wide COLUMNS keeps the synopsis on one line whatever terminal Behat runs in.
  Scenario: The command is registered and documents its options
    When I run 'COLUMNS=200 bin/ee help shell < /dev/null'
    Then the return code should be 0
    And STDOUT should contain:
      """
      ee shell [<site-name>] [--user=<user>] [--service=<service>]
      """
    And STDOUT should contain:
      """
      [--command=<command>]
      """
    And STDOUT should contain:
      """
      default: php
      """
    And STDOUT should contain:
      """
      --skip-tty=<true/false>
      """

  Scenario: No site name outside a site directory
    When I run 'bin/ee shell --command=true'
    Then the return code should be 1
    And STDOUT should be empty
    And STDERR should contain:
      """
      Error: Could not find the site you wish to run shell
      """
    And STDERR should contain:
      """
      from inside the site folder.
      """

  Scenario: No site name in interactive mode fails before opening a shell
    When I run 'bin/ee shell < /dev/null'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Could not find the site you wish to run shell
      """

  Scenario: Unknown site name
    When I run 'bin/ee shell bh-shell-command-missing.test --command=true'
    Then the return code should be 1
    And STDOUT should be empty
    And STDERR should contain:
      """
      Error: Could not find the site you wish to run shell
      """
    And the site 'bh-shell-command-missing.test' should not exist

  Scenario: The global --skip-tty parameter is accepted by ee shell
    When I run 'bin/ee shell bh-shell-command-missing.test --skip-tty --command=true'
    Then the return code should be 1
    And STDERR should not contain:
      """
      unknown --skip-tty parameter
      """
    And STDERR should contain:
      """
      Could not find the site you wish to run shell
      """

  Scenario Outline: Unknown parameters are rejected with a suggestion
    When I run 'bin/ee shell bh-shell-command-missing.test <param>'
    Then the return code should be 1
    And STDERR should contain:
      """
      Parameter errors:
      """
    And STDERR should contain:
      """
      <error>
      """

    Examples:
      | param          | error                   |
      | --foo=bar      | unknown --foo parameter |
      | --usr=root     | Did you mean '--user'?    |
      | --servce=nginx | Did you mean '--service'? |
      | --comand=ls    | Did you mean '--command'? |

  Scenario: Too many positional arguments
    When I run 'bin/ee shell bh-shell-command-a.test bh-shell-command-b.test'
    Then the return code should be 1
    And STDERR should contain:
      """
      Too many positional arguments: bh-shell-command-b.test
      """

  Scenario: global-cron is not a global shell target, so it needs a site
    When I run 'bin/ee shell --service=global-cron --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Could not find the site you wish to run shell
      """
