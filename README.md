# Custom Fresh

![Custom Fresh](https://github.com/mahmoudmohamedramadan/custom-fresh/assets/48416569/d6c582d8-72c9-4029-81d9-06e5d57fc98f "Custom Fresh")

![License](https://img.shields.io/packagist/l/ramadan/custom-fresh "License")
![Latest Version on Packagist](https://img.shields.io/packagist/v/ramadan/custom-fresh "Latest Version on Packagist")
![Total Downloads](https://img.shields.io/packagist/dt/ramadan/custom-fresh "Total Downloads")

 - - -

Custom Fresh allows fine-grain control of migrations inside your Laravel project, where you can choose which tables will not be dropped while refreshing the database.

- [Installation](#installation)
- [Usage](#usage)
  - [Refreshing migrations](#refreshing-migrations)
  - [Example](#example)
- [Credits](#credits)
- [Support me](#support-me)

## Installation

You can install the package via Composer:

```SHELL
composer require ramadan/custom-fresh
```

## Usage

After installing the package, you will now see a new `fresh:custom` command.

### Refreshing migrations

You can exclude specific tables while refreshing the database inside your project using:

```SHELL
php artisan fresh:custom posts,invalid_table_name
```

### Example

![cmd](https://github.com/mahmoudmohamedramadan/custom-fresh/assets/48416569/06cc0df0-45f7-488b-b448-7ca18ffcf767 "Custom Fresh")

## Credits

- [Mahmoud Mohamed Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://www.paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
