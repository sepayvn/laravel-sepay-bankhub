# SePay Bank Hub API cho Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sepayvn/laravel-sepay-bankhub.svg?style=flat-square)](https://packagist.org/packages/sepayvn/laravel-sepay-bankhub)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/sepayvn/laravel-sepay-bankhub/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/sepayvn/laravel-sepay-bankhub/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/sepayvn/laravel-sepay-bankhub/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/sepayvn/laravel-sepay-bankhub/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/sepayvn/laravel-sepay-bankhub.svg?style=flat-square)](https://packagist.org/packages/sepayvn/laravel-sepay-bankhub)

## Cài đặt

Bạn có thể cài đặt package thông qua composer:

```bash
composer require sepayvn/laravel-sepay-bankhub
```

Bạn có thể publish file config với lệnh:

```bash
php artisan vendor:publish --tag="laravel-sepay-bankhub-config"
```

Đây là nội dung của file config đã được publish:

```php
return [
    'api_key' => env('SEPAY_BANKHUB_API_KEY'),
    'api_secret' => env('SEPAY_BANKHUB_API_SECRET'),
    'api_url' => env('SEPAY_BANKHUB_API_URL', 'https://partner-api.sepay.vn/merchant/v1'),
];

```

## Sử dụng

```php
$sePayBankhubService = new SePay\SePayBankhub\Services\SePayBankhubService();
$sePayBankhubService->getBanks();
```

## Kiểm thử

```bash
composer test
```

## Changelog

Vui lòng xem [CHANGELOG](CHANGELOG.md) để biết thêm thông tin về những thay đổi gần đây.

## Đóng góp

Vui lòng xem [CONTRIBUTING](CONTRIBUTING.md) để biết chi tiết.

## Lỗ hổng bảo mật

Vui lòng xem [chính sách bảo mật](../../security/policy) của chúng tôi để biết cách báo cáo lỗ hổng bảo mật.

## Credits

-   [SePay](https://github.com/sepayvn)
-   [Tất cả các Contributors](../../contributors)

## Giấy phép

The MIT License (MIT). Vui lòng xem [File Giấy phép](LICENSE.md) để biết thêm thông tin.
