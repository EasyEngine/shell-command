Feature: ee shell on an html site
  An html site only has an nginx service, so the default `php` service is mapped to nginx.

  Background:
    Given the html site 'bh-shell-command-1.test' exists

  Scenario: The default service of an html site is nginx
    When I run 'bin/ee shell bh-shell-command-1.test --command="nginx -v"'
    Then the return code should be 0
    And STDERR should contain:
      """
      nginx version: openresty
      """

  Scenario: An explicit --service=php is also mapped to nginx on an html site
    When I run 'bin/ee shell bh-shell-command-1.test --service=php --command="nginx -v"'
    Then the return code should be 0
    And STDERR should contain:
      """
      nginx version: openresty
      """

  Scenario: --service=nginx runs as the image default user in the web root
    When I run 'bin/ee shell bh-shell-command-1.test --service=nginx --command="whoami; pwd"'
    Then the return code should be 0
    And STDOUT should be:
      """
      root
      /var/www/htdocs
      """

  Scenario: The shell sees the site's web root
    When I run 'bin/ee shell bh-shell-command-1.test --command="test -f /var/www/htdocs/index.html && echo has-index"'
    Then the return code should be 0
    And STDOUT should be:
      """
      has-index
      """

  Scenario Outline: Services the html site does not have are rejected
    When I run 'bin/ee shell bh-shell-command-1.test --service=<service> --command=true'
    Then the return code should be 1
    And STDOUT should be empty
    And STDERR should contain:
      """
      Error: bh-shell-command-1.test site does not have support to launch <service> shell.
      """

    Examples:
      | service     |
      | db          |
      | redis       |
      | mailhog     |
      | postfix     |
      | global-cron |

  Scenario: --service without a value warns and fails on the empty service
    When I run 'bin/ee shell bh-shell-command-1.test --service --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Warning: --service parameter needs a value
      """
    And STDERR should contain:
      """
      site does not have support to launch
      """

  Scenario: A trailing slash in the site name is accepted
    When I run 'bin/ee shell bh-shell-command-1.test/ --command=pwd'
    Then the return code should be 0
    And STDOUT should be:
      """
      /var/www/htdocs
      """

  Scenario: The site is detected from the site root directory
    Given I am in the directory '/opt/easyengine/sites/bh-shell-command-1.test'
    When I run 'bin/ee shell --command=pwd'
    Then the return code should be 0
    And STDOUT should be:
      """
      /var/www/htdocs
      """

  Scenario: The site is detected from the (symlinked) web root directory
    Given I am in the directory '/opt/easyengine/sites/bh-shell-command-1.test/app/htdocs'
    When I run 'bin/ee shell --command=pwd'
    Then the return code should be 0
    And STDOUT should be:
      """
      /var/www/htdocs
      """

  Scenario: The site is detected from the site directory in interactive mode
    Given I am in the directory '/opt/easyengine/sites/bh-shell-command-1.test'
    When I run 'printf "echo detected\n" | bin/ee shell'
    Then the return code should be 0
    And STDOUT should be:
      """
      detected
      """

  Scenario: A wrong site name inside a site directory suggests the detected site
    Given I am in the directory '/opt/easyengine/sites/bh-shell-command-1.test'
    When I run 'bin/ee shell bh-shell-command-nope.test --command=pwd'
    Then the return code should be 1
    And STDERR should contain:
      """
      bh-shell-command-nope.test is not a valid site-name. Did you mean `ee shell
      """
    And STDERR should contain:
      """
      bh-shell-command-1.test`?
      """

  Scenario: A stopped service container makes the command fail
    Given the service 'nginx' of site 'bh-shell-command-1.test' is stopped
    When I run 'bin/ee shell bh-shell-command-1.test --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      is not running
      """
    When the service 'nginx' of site 'bh-shell-command-1.test' is started
    Then I wait until the service 'nginx' of site 'bh-shell-command-1.test' is running up to 30 seconds
    When I run 'bin/ee shell bh-shell-command-1.test --command=true'
    Then the return code should be 0

  Scenario: A stopped service container makes the interactive shell fail
    Given the service 'nginx' of site 'bh-shell-command-1.test' is stopped
    When I run 'bin/ee shell bh-shell-command-1.test < /dev/null'
    Then the return code should not be 0
    And STDERR should contain:
      """
      is not running
      """

  Scenario: A disabled site is refused
    Given the site 'bh-shell-command-1.test' is disabled
    When I run 'bin/ee shell bh-shell-command-1.test --command=true'
    Then the return code should be 1
    And STDOUT should be empty
    And STDERR should contain:
      """
      Error: Site bh-shell-command-1.test is not enabled. Use `ee site enable bh-shell-command-1.test` to enable it.
      """

  Scenario: A disabled site is refused in interactive mode too
    Given the site 'bh-shell-command-1.test' is disabled
    When I run 'bin/ee shell bh-shell-command-1.test < /dev/null'
    Then the return code should be 1
    And STDERR should contain:
      """
      is not enabled
      """

  Scenario: Running the same command twice gives the same result
    When I run 'bin/ee shell bh-shell-command-1.test --command="cat /etc/hostname"'
    And I run 'bin/ee shell bh-shell-command-1.test --command="cat /etc/hostname"'
    Then the return code should be 0
    And the outputs of the last two commands should be identical
