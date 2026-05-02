# Custom Fresh

![Custom Fresh](/art/logo.png "Custom Fresh")

![Latest Version](https://img.shields.io/packagist/v/ramadan/custom-fresh?style=flat-square&logo=packagist)
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh?style=flat-square)
![PHP](https://img.shields.io/badge/php-%5E8.2-777BB4?logo=php&style=flat-square)
![Laravel](https://img.shields.io/badge/laravel-%5E10.0%7C%5E11.0%7C%5E12.0%7C%5E13.0-FF2D20?logo=laravel&style=flat-square)
![License](https://img.shields.io/packagist/l/ramadan/custom-fresh?style=flat-square)

 - - -

Custom Fresh offers fine-grained control over migrations within your Laravel project, enabling you to select which tables will not be dropped when refreshing the database.

> [!IMPORTANT]  
> Additional features are currently in progress and being prepared on the [development branch](https://github.com/mahmoudmohamedramadan/custom-fresh/tree/development).
>
> [!TIP]
> Always consider upgrading the package to the [latest](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/latest) version, which is the most stable release.

- [Installation](#installation)
- [Usage](#usage)
  - [Refreshing migrations](#refreshing-migrations)
  - [Example](#example)
- [Credits](#credits)
- [Support me](#support-me)

## Installation

Install the package by using [Composer](https://getcomposer.org/):

```SHELL
composer require ramadan/custom-fresh
```

## Usage

After installing the package, you will see a new `fresh:custom` command.

> [!NOTE]
> The package also guesses the additional migration files that add a special column (e.g., `****_**_**_******_adds_is_admin_column_to_users_table.php`).

### Refreshing migrations

You can exclude specific tables while refreshing the database inside your project:

```SHELL
php artisan fresh:custom users,foo
```

> [!IMPORTANT]
> Do not forget always to use the `-h` of the command to check out all supported options.

### Example

![Custom Fresh CLI Command Example](/art/screenshot.png)

## Credits

- [Mahmoud Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
