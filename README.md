easyengine/shell-command
========================

Shell to run helpful commands inside containers.



Quick links: [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Using

~~~
ee shell [<site-name>] [--user=<user>] [--service=<service>] [--command=<command>] [--skip-tty]
~~~

**OPTIONS**

	[<site-name>]
		Name of website to run shell on.

	[--user=<user>]
		Set the user to exec into shell.

	[--service=<service>]
		Set the service whose shell you want.
		---
		default: php
		---

	[--command=<command>]
		Command to non-interactively run in the shell.

	[--skip-tty]
		Skips tty allocation.

 **EXAMPLES**

    # Open shell for site
    $ ee shell example.com

    # Open shell with root user
    $ ee shell example.com --user=root

    # Open shell for some other service
    $ ee shell example.com --service=nginx

    # Run command non-interactively
    $ ee shell example.com --service=nginx --command='nginx -t && nginx -s reload'

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/easyengine/shell-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/easyengine/shell-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible.

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/easyengine/shell-command/issues/new) to discuss whether the feature is a good fit for the project.

## Support

Github issues aren't for general support questions, but there are other venues you can try: https://easyengine.io/support/


*This README.md is generated dynamically from the project's codebase using `ee scaffold package-readme` ([doc](https://github.com/EasyEngine/scaffold-command)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
