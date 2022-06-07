# Contributing

When contributing to this repository, please first discuss the change you wish to make via issue, email, or any other method with the owners of this repository before making a change.
Please note we have a [code of conduct](CODE_OF_CONDUCT.md), please follow it in all your interactions with the project.

## Development environment setup

To set up a development environment, please follow these steps:

1. Clone the repo

   ```sh
   git clone https://github.com/grommunio/grommunio-sync
   ```

2. Install up to a working setup (nginx, php-fpm, redis).

3. After creation (or modification) of code modifying core structures, the included autoload code (composer2) should be updated as well.

   ```sh
   curl -sS https://getcomposer.org/installer | php
   php composer.phar dump-autoload -o
   ```

4. After creation (or modification) of code, make sure your code follows the repositories coding style.

   ```sh
   composer require --working-dir=tools/php-cs-fixer friendsofphp/php-cs-fixer
   tools/php-cs-fixer/vendor/friendsofphp/php-cs-fixer/php-cs-fixer --config=.phpcs fix ./
   ```

5. Validation of correct setup is possible by pointing a browser to https://<URL>/Microsoft-Server-ActiveSync which should require to authenticate with valid credentials. The message "GET not supported" after successful authentication is correct, since EAS does not operate on HTTP GET.

6. Validation of development environment is best when at least a mobile device account is successfully connected.

## Issues and feature requests

You've found a bug in the source code, a mistake in the documentation or maybe you'd like a new feature? You can help us by [submitting an issue on GitHub](https://github.com/grommunio/grommunio-sync/issues). Before you create an issue, make sure to search the issue archive -- your issue may have already been addressed!

Please try to create bug reports that are:

- _Reproducible._ Include steps to reproduce the problem.
- _Specific._ Include as much detail as possible: which version, what environment, etc.
- _Unique._ Do not duplicate existing opened issues.
- _Scoped to a Single Bug._ One bug per report.

**Even better: Submit a pull request with a fix or new feature!**

### How to submit a Pull Request

1. Search our repository for open or closed
   [Pull Requests](https://github.com/grommunio/grommunio-sync/pulls)
   that relate to your submission. You don't want to duplicate effort.
2. Fork the project
3. Create your feature branch (`git checkout -b feat/amazing_feature`)
4. Commit your changes (`git commit -m 'feat: add amazing_feature'`) grommunio Sync uses [conventional commits](https://www.conventionalcommits.org), so please follow the specification in your commit messages.
5. Make sure your contribution follows the repository coding style
6. Push to the branch (`git push origin feat/amazing_feature`)
7. [Open a Pull Request](https://github.com/grommunio/grommunio-sync/compare?expand=1)
