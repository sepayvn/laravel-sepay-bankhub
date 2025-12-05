# SePay Bank Hub API for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sepayvn/laravel-sepay-bankhub.svg?style=flat-square)](https://packagist.org/packages/sepayvn/laravel-sepay-bankhub)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sepayvn/laravel-sepay-bankhub/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sepayvn/laravel-sepay-bankhub/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sepayvn/laravel-sepay-bankhub/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sepayvn/laravel-sepay-bankhub/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sepayvn/laravel-sepay-bankhub.svg?style=flat-square)](https://packagist.org/packages/sepayvn/laravel-sepay-bankhub)

## Installation

You can install the package via composer:

```bash
composer require sepayvn/laravel-sepay-bankhub
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-sepay-bankhub-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-sepay-bankhub-views"
```

## Usage

```php
$sePayBankhub = new SePay\SePayBankhub();
echo $sePayBankhub->echoPhrase('Hello, SePay!');
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [SePay](https://github.com/sepayvn)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
