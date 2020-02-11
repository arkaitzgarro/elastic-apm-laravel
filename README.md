# Elastic APM

[![CircleCI](https://circleci.com/gh/arkaitzgarro/elastic-apm-laravel.svg?style=svg)](https://circleci.com/gh/arkaitzgarro/elastic-apm-laravel)

Elastic APM agent for v2 intake API. Compatible with Laravel 5.5+.

## Installation

Require this package with composer:

    composer require arkaitzgarro/elastic-apm-laravel

Add the ServiceProvider class to the providers array in `config/app.php`:

```php
'providers' => [
    // ... more providers
    \AG\ElasticApmLaravel\ServiceProvider::class,
],
```

From here, we will take care of everything based on your configuration. The agent and the middleware will be registered, and transactions will be send to Elastic.

However, there is a caveat if you'd like to include Job tracking. You must include Job middleware manually or these transactions will not be recorded (Laravel 6+ only https://laravel.com/docs/6.x/queues#job-middleware):

```php
public function middleware()
{
    return [
        app(\AG\ElasticApmLaravel\Jobs\Middleware\RecordTransaction::class),
    ];
}
```

## Agent configuration

The following environment variables are supported in the default configuration:

| Variable          | Description |
|-------------------|-------------|
|APM_ACTIVE         | `true` or `false` defaults to `true`. If `false`, the agent will collect, but not send, transaction data. |
|APM_APPNAME        | Name of the app as it will appear in APM. Invalid special characters will be replaced with a hyphen. |
|APM_APPVERSION     | Version of the app as it will appear in APM. |
|APM_SERVERURL      | URL to the APM intake service. |
|APM_SECRETTOKEN    | Secret token, if required. |
|APM_USEROUTEURI    | `true` or `false` defaults to `true`. The default behavior is to record the URL as defined in your routes configuration. Set to `false` to record the requested URL, but keep in mind that this can result in excessive unique entries in APM. |
|APM_QUERYLOG       | `true` or `false` defaults to 'true'. Set to `false` to completely disable query logging, or to `auto` if you would like to use the threshold feature. |
|APM_THRESHOLD      | Query threshold in milliseconds, defaults to `200`. If a query takes longer then 200ms, we enable the query log. Make sure you set `APM_QUERYLOG=auto`. |
|APM_BACKTRACEDEPTH | Defaults to `25`. Depth of backtrace in query span. |
|APM_MAXTRACEITEMS  | Defaults to `1000`. Max number of child items displayed when viewing trace details. |

You may also publish the `elastic-apm-laravel.php` configuration file to change additional settings:

```bash
php artisan vendor:publish --tag=config
```

Once published, open the `config/elastic-apm-laravel.php` file and review the various settings.

## Development

Get Composer. Follow the instructions defined on the official [Composer page](https://getcomposer.org/doc/00-intro.md), or if you are using `homebrew`, just run:

```bash
brew install composer
```

Install project dependencies:

```bash
composer install
```

Run the unit test suite:

```bash
php vendor/bin/codecept run unit
```

Please adhere to [PSR-2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md) and [Symfony](https://symfony.com/doc/current/contributing/code/standards.html) coding standard. Run the following commands before pushing your code:

```bash
php ./vendor/bin/php-cs-fixer fix --config .php_cs
```
