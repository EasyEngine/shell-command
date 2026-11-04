Feature: ee shell on a WordPress site
  WP-CLI runs in the php container as www-data; a default wp site uses the global db, so it has no db service.

  Background:
    Given the wp site 'bh-shell-command-4.test' exists

  Scenario: WP-CLI works without --allow-root
    When I run 'bin/ee shell bh-shell-command-4.test --command="wp core version"'
    Then the return code should be 0
    And STDOUT should match '/^\d+\.\d+(\.\d+)?$/m'
    And STDERR should not contain:
      """
      allow-root
      """

  Scenario: WP-CLI reads the site's database
    When I run 'bin/ee shell bh-shell-command-4.test --command="wp option get siteurl"'
    Then the return code should be 0
    And STDOUT should be:
      """
      http://bh-shell-command-4.test
      """

  Scenario: The site is wired to the global database
    When I run 'bin/ee shell bh-shell-command-4.test --command="wp config get DB_HOST"'
    Then the return code should be 0
    And STDOUT should contain:
      """
      global-db
      """

  Scenario: A failing WP-CLI command fails ee shell and shows WP-CLI's error
    When I run 'bin/ee shell bh-shell-command-4.test --command="wp option get bh-no-such-option"'
    Then the return code should be 1
    And STDERR should contain:
      """
      Could not get 'bh-no-such-option' option
      """

  Scenario: WP-CLI through the interactive shell
    When I run 'printf "wp option get blogname >/dev/null && echo wp-ok\n" | bin/ee shell bh-shell-command-4.test'
    Then the return code should be 0
    And STDOUT should be:
      """
      wp-ok
      """

  Scenario: A site on the global db has no db service shell
    When I run 'bin/ee shell bh-shell-command-4.test --service=db --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: bh-shell-command-4.test site does not have support to launch db shell.
      """

  Scenario: The nginx service shell can test and reload nginx
    When I run 'bin/ee shell bh-shell-command-4.test --service=nginx --command="nginx -t && nginx -s reload"'
    Then the return code should be 0
    And STDERR should contain:
      """
      test is successful
      """

  Scenario: The postfix service shell runs in the postfix container
    When I run 'bin/ee shell bh-shell-command-4.test --service=postfix --command=hostname'
    Then the return code should be 0
    And STDOUT should be:
      """
      bh-shell-command-4.test
      """

  # The container is started directly: `ee mailhog enable` would also create the global admin-tools auth, which is global state.
  Scenario: The mailhog shell uses sh (the image has no bash) once the mailhog container runs
    When I run 'bin/ee shell bh-shell-command-4.test --service=mailhog --command=true'
    Then the return code should be 1
    And STDERR should contain:
      """
      is not running
      """
    Given the service 'mailhog' of site 'bh-shell-command-4.test' is up
    When I run 'bin/ee shell bh-shell-command-4.test --service=mailhog --command="echo from-sh; id -un"'
    Then the return code should be 0
    And STDOUT should be:
      """
      from-sh
      mailhog
      """
    When I run 'printf "echo interactive-sh\n" | bin/ee shell bh-shell-command-4.test --service=mailhog'
    Then the return code should be 0
    And STDOUT should be:
      """
      interactive-sh
      """
