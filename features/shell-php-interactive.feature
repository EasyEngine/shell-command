Feature: Interactive ee shell on a php site
  Without --command, ee hands its own STDIN/STDOUT/STDERR to `docker-compose exec <service> bash`.
  Steps never inherit Behat's terminal: STDIN is always piped or /dev/null, and a real TTY is created with `script` where needed.

  Background:
    Given the php site 'bh-shell-command-3.test' exists

  Scenario: Commands piped to STDIN run in the php container as www-data
    When I run 'printf "whoami\npwd\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be 0
    And STDOUT should be:
      """
      www-data
      /var/www/htdocs
      """

  Scenario: End of input closes the shell cleanly
    When I run 'bin/ee shell bh-shell-command-3.test < /dev/null'
    Then the return code should be 0
    And STDOUT should be empty

  Scenario Outline: The exit status of the shell is propagated
    When I run 'printf "exit <code>\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be <code>

    Examples:
      | code |
      | 0    |
      | 3    |
      | 42   |
      | 255  |

  Scenario: The status of the last command is propagated
    When I run 'printf "true\nfalse\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be 1

  Scenario: An unknown binary gives the shell's 127
    When I run 'printf "bh-no-such-binary\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be 127
    And STDERR should contain:
      """
      bh-no-such-binary: command not found
      """

  Scenario: STDOUT and STDERR of the shell stay separate
    When I run 'printf "echo to-out\necho to-err >&2\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be 0
    And STDOUT should be:
      """
      to-out
      """
    And STDERR should contain:
      """
      to-err
      """

  Scenario: Shell state persists between lines of the session
    When I run 'printf "cd /tmp\nX=kept\necho \$X \$(pwd)\n" | bin/ee shell bh-shell-command-3.test'
    Then the return code should be 0
    And STDOUT should be:
      """
      kept /tmp
      """

  Scenario: An unknown --user falls back to the default user with a warning
    When I run 'printf "whoami\n" | bin/ee shell bh-shell-command-3.test --user=bh-nouser'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: bh-nouser is not available in php, falling back to default user.
      """

  Scenario: --service selects the container of the interactive shell
    When I run 'printf "whoami\nhostname\n" | bin/ee shell bh-shell-command-3.test --service=postfix'
    Then the return code should be 0
    And STDOUT should be:
      """
      root
      bh-shell-command-3.test
      """

  Scenario: An unsupported service is rejected before a shell opens
    When I run 'bin/ee shell bh-shell-command-3.test --service=db < /dev/null'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: bh-shell-command-3.test site does not have support to launch db shell.
      """

  Scenario: --skip-tty keeps working with piped input
    When I run 'printf "echo piped\nexit 6\n" | bin/ee shell bh-shell-command-3.test --skip-tty'
    Then the return code should be 6
    And STDOUT should be:
      """
      piped
      """

  Scenario: From a terminal the shell gets a TTY and still propagates the exit status
    When I run 'printf "tty\nexit 5\n" | script -qec "bin/ee shell bh-shell-command-3.test" /dev/null'
    Then the return code should be 5
    And STDOUT should contain:
      """
      /dev/pts/
      """

  Scenario: From a terminal with STDIN redirected, docker refuses the TTY
    When I run 'script -qec "bin/ee shell bh-shell-command-3.test < /dev/null" /dev/null < /dev/null'
    Then the return code should not be 0
    And STDOUT should contain:
      """
      the input device is not a TTY
      """
