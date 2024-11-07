# Custom Fresh

![Custom Fresh](https://raw.githubusercontent.com/mahmoudmohamedramadan/custom-fresh/refs/heads/main/assets/custom-fresh.png "Custom Fresh")

![License](https://img.shields.io/packagist/l/ramadan/custom-fresh "License")
![Latest Version on Packagist](https://img.shields.io/packagist/v/ramadan/custom-fresh "Latest Version on Packagist")
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh "Total Downloads")

 - - -

Custom Fresh allows fine-grain control of migrations inside your Laravel project, where you can choose which tables will not be dropped while refreshing the database.

> [!WARNING]
> Always consider upgrading the package to the latest version ([v1.1.6](https://github.com/mahmoudmohamedramadan/custom-fresh/releases/tag/v1.1.6)), which is the most stable release.

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

### Example

![Command Example](https://raw.githubusercontent.com/mahmoudmohamedramadan/custom-fresh/refs/heads/main/assets/command-example.png "Command Example")

## Credits

- [Mahmoud Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://www.paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
