Feature: ee shell on global services
  --service=global-nginx-proxy|global-db|global-redis opens a shell in the global container and ignores the site argument.

  Background:
    # global-redis and global-db only exist once a site uses them; this site makes the feature work on a fresh stack.
    Given the php site 'bh-shell-command-5.test' exists with flags '--with-db --cache'

  Scenario: global-redis shell
    When I run 'bin/ee shell --service=global-redis --command="redis-cli ping"'
    Then the return code should be 0
    And STDOUT should be:
      """
      PONG
      """

  Scenario: global-nginx-proxy shell
    When I run 'bin/ee shell --service=global-nginx-proxy --command="nginx -t"'
    Then the return code should be 0
    And STDERR should contain:
      """
      test is successful
      """

  Scenario: The site argument is ignored for global services, even if the site does not exist
    When I run 'bin/ee shell bh-shell-command-missing.test --service=global-redis --command="redis-cli ping"'
    Then the return code should be 0
    And STDOUT should be:
      """
      PONG
      """

  Scenario: A failing command in a global service fails ee
    When I run 'bin/ee shell --service=global-redis --command="echo before; exit 4"'
    Then the return code should not be 0
    And STDOUT should be:
      """
      before
      """

  Scenario: Interactive shell on a global service propagates the exit status
    When I run 'printf "redis-cli ping\nexit 9\n" | bin/ee shell --service=global-redis'
    Then the return code should be 9
    And STDOUT should be:
      """
      PONG
      """

  Scenario: An unknown --user on a global service falls back to the default user
    When I run 'bin/ee shell --service=global-redis --user=bh-nouser --command=whoami'
    Then the return code should be 0
    And STDERR should contain:
      """
      Warning: bh-nouser is not available in global-redis, falling back to default user.
      """
    And STDOUT should be:
      """
      root
      """

  @destructive
  Scenario: The global-db shell creates my.cnf for password-less mysql
    Given the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is restored after the scenario
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is removed
    When I run 'bin/ee shell --service=global-db --command="mariadb -Nse 'SELECT 42'"'
    Then the return code should be 0
    And STDOUT should be:
      """
      42
      """
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' should contain:
      """
      [mysql]
      user=root
      password=
      """

  @destructive
  Scenario: An existing my.cnf is not overwritten
    Given the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is restored after the scenario
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is removed
    When I run 'bin/ee shell --service=global-db --command=true'
    Then the return code should be 0
    Given the line '# bh-shell-command marker' is appended to the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf'
    When I run 'bin/ee shell --service=global-db --command="mariadb -Nse 'SELECT 1'"'
    Then the return code should be 0
    And STDOUT should be:
      """
      1
      """
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' should contain:
      """
      # bh-shell-command marker
      """

  @destructive
  Scenario: Interactive global-db shell uses the generated my.cnf
    Given the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is restored after the scenario
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' is removed
    When I run 'echo "mariadb -Nse 'SELECT 7'" | bin/ee shell --service=global-db'
    Then the return code should be 0
    And STDOUT should be:
      """
      7
      """
    And the file '/opt/easyengine/services/mariadb/conf/conf.d/my.cnf' should exist

  @destructive
  Scenario: A stopped global service is reported, in both modes
    Given the docker container 'services_global-redis_1' is stopped
    When I run 'bin/ee shell --service=global-redis --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: global-redis service container is not running.
      """
    When I run 'bin/ee shell --service=global-redis < /dev/null'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: global-redis service container is not running.
      """
    When the docker container 'services_global-redis_1' is started
    Then I wait until the docker container 'services_global-redis_1' is running up to 30 seconds
