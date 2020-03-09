# Elastic APM

[![CircleCI](https://circleci.com/gh/arkaitzgarro/elastic-apm-laravel.svg?style=svg)](https://circleci.com/gh/arkaitzgarro/elastic-apm-laravel)
[![Latest Stable Version](https://poser.pugx.org/arkaitzgarro/elastic-apm-laravel/v/stable)](https://packagist.org/packages/arkaitzgarro/elastic-apm-laravel)
[![License](https://poser.pugx.org/arkaitzgarro/elastic-apm-laravel/license)](https://packagist.org/packages/arkaitzgarro/elastic-apm-laravel)

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

From here, we will take care of everything based on your configuration. The agent and the middleware will be registered, and transactions will be sent to Elastic.

## Collectors

The default collectors typically listen on events to measure portions of the request such as framework loading, database queries, or jobs.

The SpanCollector in particular allows you to measure any section of your own code via the `ApmCollector` Facade:

```php
use AG\ElasticApmLaravel\Facades\ApmCollector;

ApmCollector::startMeasure('my-custom-span', 'custom', 'measure', 'My custom span');

// do something amazing

ApmCollector::stopMeasure('my-custom-span');
```

To record an additional span around your job execution, you may include the provided job middleware (Laravel 6+ only https://laravel.com/docs/6.x/queues#job-middleware):

```php
public function middleware()
{
    return [
        app(\AG\ElasticApmLaravel\Jobs\Middleware\RecordTransaction::class),
    ];
}
```

### Add a collector for other events

You can add extra collector(s) to listen to your own application events or Laravel events like `Illuminate\Mail\Events\MessageSending` for example. We created a base collector that already includes functionality to measure events, that you can extend from:

```php
// app/Collectors/MailMessageCollector.php

namespace YourApp\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\Collectors\EventDataCollector;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;

class MailMessageCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'mail-message-collector';
    }

    protected function registerEventListeners(): void
    {
        $this->app->events->listen(MessageSending::class, function (\Swift_Message $message) {
            $this->startMeasure(
                'mail #' . $message->getId(),
                'mail.delivery',
            );
        });

        $this->app->events->listen(StopMeasuring::class, function (\Swift_Message $message) {
            $this->stopMeasure('mail #' . $message->getId());
        });
    }
}

```

Don't forget to register your collector when the application starts:

```php
// app/Providers/AppServiceProvider.php

use AG\ElasticApmLaravel\Facades\ApmCollector;

use YourApp\Collectors\MailMessageCollector;

public function boot()
{
    // ...
    ApmCollector::addCollector(MailMessageCollector::class);
}
```

## Agent configuration

The following environment variables are supported in the default configuration:

| Variable          | Description |
|-------------------|-------------|
|APM_ACTIVE         | `true` or `false` defaults to `true`. If `false`, the agent will collect, but not send, transaction data; span collection will also be disabled. |
|APM_ACTIVE_CLI     | `true` or `false` defaults to `true`. If `false`, the agent will not collect or send transaction or span data for non-HTTP requests but HTTP requests will still follow APM_ACTIVE. When APM_ACTIVE is `false`, this will have no effect. |
|APM_APPNAME        | Name of the app as it will appear in APM. Invalid special characters will be replaced with a hyphen. |
|APM_APPVERSION     | Version of the app as it will appear in APM. |
|APM_SERVERURL      | URL to the APM intake service. |
|APM_SECRETTOKEN    | Secret token, if required. |
|APM_USEROUTEURI    | `true` or `false` defaults to `true`. The default behavior is to record the URL as defined in your routes configuration. Set to `false` to record the requested URL, but keep in mind that this can result in excessive unique entries in APM. |
|APM_IGNORE_PATTERNS| Ignore specific routes by transaction name. Should be a regular expression, and will match multiple patterns via pipe `\|` in the regex. Example: `"/\/health-check\|^OPTIONS /"` |
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
