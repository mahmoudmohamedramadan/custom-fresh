# Custom Fresh

![Custom Fresh](https://user-images.githubusercontent.com/48416569/180634178-2ea1ce4c-6e6d-4374-843d-6175785238f6.png "Custom Fresh")

![License](https://img.shields.io/packagist/l/ramadan/custom-fresh "License")
![Latest Version on Packagist](https://img.shields.io/packagist/v/ramadan/custom-fresh "Latest Version on Packagist")
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh "Total Downloads")

 - - -

Custom Fresh allows fine-grain control of migrations inside your Laravel project. You can choose which tables will not be dropped during refreshing the database.

- [Installation](#installation)
- [Usage](#usage)
  - [Refreshing migrations](#refreshing-migrations)
  - [Example](#example)
- [Credits](#credits)
- [Support me](#support-me)

## Installation

You can install the package via composer:

```SHELL
composer require ramadan/custom-fresh
```

## Usage

After installing the package, you will now see a new `fresh:custom` command.

### Refreshing migrations

You can exclude specific tables during refreshing the database inside your project using:

```SHELL
php artisan fresh:custom posts invalid_table_name
```

### Example

![cmd](https://user-images.githubusercontent.com/48416569/200514609-3c244963-cb12-4eb9-b68c-700c39cdcc6d.png "Custom Fresh")

## Credits

- [Mahmoud Mohamed Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://www.paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
