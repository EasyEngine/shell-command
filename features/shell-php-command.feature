Feature: ee shell --command on a php site
  Non-interactive mode: the command runs through `bash -c` in the service container, output is captured and replayed.

  Background:
    Given the php site 'bh-shell-command-2.test' exists with flags '--with-db --local-db --php=8.4'

  Scenario: STDOUT of the command is printed
    When I run 'bin/ee shell bh-shell-command-2.test --command="echo hello-from-php"'
    Then the return code should be 0
    And STDOUT should be:
      """
      hello-from-php
      """
    And STDERR should be empty

  Scenario: The default service is php, as www-data, in the web root
    When I run 'bin/ee shell bh-shell-command-2.test --command="whoami; pwd"'
    Then the return code should be 0
    And STDOUT should be:
      """
      www-data
      /var/www/htdocs
      """

  Scenario: The php container runs the site's PHP version
    When I run 'bin/ee shell bh-shell-command-2.test --command="php -v"'
    Then the return code should be 0
    And STDOUT should match '/^PHP 8\.4\./m'

  Scenario: STDERR is kept separate and a failing command fails ee
    When I run 'bin/ee shell bh-shell-command-2.test --command="echo out; echo err >&2; exit 3"'
    Then the return code should not be 0
    And STDOUT should be:
      """
      out
      """
    And STDERR should contain:
      """
      err
      """

  # Only statuses that hold with and without a fix for the collapsed exit status; the exact-status cases come back with that fix.
  Scenario Outline: Exit status of the command
    When I run 'bin/ee shell bh-shell-command-2.test --command="<command>"'
    Then the return code should be <code>

    Examples:
      | command  | code |
      | true     | 0    |
      | false    | 1    |
      | exit 1   | 1    |

  # Non-zero only: bash exits 127, which --command currently collapses to 1.
  Scenario: An unknown binary fails with the shell's error
    When I run 'bin/ee shell bh-shell-command-2.test --command=bh-no-such-binary'
    Then the return code should not be 0
    And STDERR should contain:
      """
      bh-no-such-binary: command not found
      """

  Scenario Outline: Shell syntax is interpreted inside the container
    When I run 'bin/ee shell bh-shell-command-2.test --command="<command>"'
    Then the return code should be 0
    And STDOUT should be:
      """
      <output>
      """

    Examples:
      | command                              | output    |
      | cd /tmp && pwd                       | /tmp      |
      | echo abc \| tr a-c x-z               | xyz       |
      | false \|\| echo recovered            | recovered |
      | echo 'single  quoted'                | single  quoted |
      | test -d /var/www/htdocs && echo yes  | yes       |

  Scenario Outline: STDIN is forwarded to the command
    When I run 'printf "l1\nl2\n" | bin/ee shell bh-shell-command-2.test <flag> --command="wc -l"'
    Then the return code should be 0
    And STDOUT should be:
      """
      2
      """

    Examples:
      | flag       |
      |            |
      | --skip-tty |

  Scenario: Multi-line output is preserved
    When I run 'bin/ee shell bh-shell-command-2.test --command="seq 1 3"'
    Then the return code should be 0
    And STDOUT should be:
      """
      1
      2
      3
      """

  Scenario Outline: --skip-tty is accepted in command mode
    When I run 'bin/ee shell bh-shell-command-2.test <flag> --command="echo ok"'
    Then the return code should be 0
    And STDOUT should be:
      """
      ok
      """

    Examples:
      | flag            |
      | --skip-tty      |
      | --skip-tty=true |

  Scenario: --skip-tty does not hide a failure
    When I run 'bin/ee shell bh-shell-command-2.test --skip-tty --command=false'
    Then the return code should be 1

  # docker-compose v2 disables the TTY whenever its stdout is a pipe, so this can't tell whether --skip-tty added -T.
  Scenario: Command mode never allocates a TTY, with or without --skip-tty
    When I run 'bin/ee shell bh-shell-command-2.test --command="[ -t 1 ] && echo tty || echo notty"'
    Then the return code should be 0
    And STDOUT should be:
      """
      notty
      """
    When I run 'bin/ee shell bh-shell-command-2.test --skip-tty --command="[ -t 1 ] && echo tty || echo notty"'
    Then the return code should be 0
    And STDOUT should be:
      """
      notty
      """

  Scenario: Command mode captures output even when ee runs in a terminal
    When I run 'script -qec "bin/ee shell bh-shell-command-2.test --command=tty" /dev/null < /dev/null'
    Then the return code should be 1
    And STDOUT should contain:
      """
      not a tty
      """

  # Today it then falls back to the interactive shell (a known bug), so only the warning is pinned here.
  Scenario: --command without a value warns
    When I run 'bin/ee shell bh-shell-command-2.test --command < /dev/null'
    Then STDERR should contain:
      """
      Warning: --command parameter needs a value
      """

  Scenario: An unknown --user falls back to the default user with a warning
    When I run 'bin/ee shell bh-shell-command-2.test --user=bh-nouser --command=whoami'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: bh-nouser is not available in php, falling back to default user.
      """
    And STDOUT should be:
      """
      www-data
      """

  Scenario: --user without a value warns and uses the default user
    When I run 'bin/ee shell bh-shell-command-2.test --user --command=whoami'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: --user parameter needs a value
      """
    And STDOUT should be:
      """
      www-data
      """

  Scenario: The docblock example on the nginx service works
    When I run 'bin/ee shell bh-shell-command-2.test --service=nginx --command="nginx -t && nginx -s reload"'
    Then the return code should be 0
    And STDERR should contain:
      """
      test is successful
      """

  Scenario: The postfix service shell runs in the postfix container
    When I run 'bin/ee shell bh-shell-command-2.test --service=postfix --command=hostname'
    Then the return code should be 0
    And STDOUT should be:
      """
      bh-shell-command-2.test
      """

  # The php image also ships a mariadb client, so check for the server's datadir instead.
  Scenario: The local db service shell runs in the site's mariadb container
    When I run 'bin/ee shell bh-shell-command-2.test --service=db --command="test -d /var/lib/mysql/mysql && echo has-datadir"'
    Then the return code should be 0
    And STDOUT should be:
      """
      has-datadir
      """

  Scenario: A service that exists but is not started fails at exec time
    When I run 'bin/ee shell bh-shell-command-2.test --service=mailhog --command=true'
    Then the return code should be 1
    And STDERR should not contain:
      """
      does not have support to launch
      """
    And STDERR should contain:
      """
      is not running
      """

  Scenario: A service that is not in the compose file is rejected
    When I run 'bin/ee shell bh-shell-command-2.test --service=redis --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: bh-shell-command-2.test site does not have support to launch redis shell.
      """

  Scenario: A service from docker-compose-custom.yml gets a shell, and --custom-compose overrides it
    Given the file '/opt/easyengine/sites/bh-shell-command-2.test/docker-compose-custom.yml' is restored after the scenario
    And the file '/opt/easyengine/sites/bh-shell-command-2.test/docker-compose-custom.yml' contains:
      """
      services:
        bh-extra:
          image: easyengine/redis:v4.11.0
          networks:
            - bh-shell-command-2.test
      """
    And the service 'bh-extra' of site 'bh-shell-command-2.test' is up
    When I run 'bin/ee shell bh-shell-command-2.test --service=bh-extra --command="redis-cli ping"'
    Then the return code should be 0
    And STDOUT should be:
      """
      PONG
      """
    When I run 'bin/ee shell bh-shell-command-2.test --custom-compose=bh-missing.yml --service=bh-extra --command="redis-cli ping"'
    Then the return code should be 1
    And STDERR should contain:
      """
      Warning: File: user-docker-compose/bh-missing.yml does not exist. Falling back to default compose file.
      """
    And STDERR should contain:
      """
      Error: bh-shell-command-2.test site does not have support to launch bh-extra shell.
      """
