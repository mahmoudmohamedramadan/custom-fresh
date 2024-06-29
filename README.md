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

Install the package by using [Composer](https://getcomposer.org/):

```SHELL
composer require ramadan/custom-fresh
```

## Usage

After installing the package, you will see a new `fresh:custom` command.

### Refreshing migrations

You can exclude specific tables while refreshing the database inside your project:

```SHELL
php artisan fresh:custom posts,foo,bar
```

### Example

![bash](https://github.com/mahmoudmohamedramadan/custom-fresh/assets/48416569/820c65fc-95e4-442c-b74c-e255d3232c63 "Custom Fresh")

## Credits

- [Mahmoud Mohamed Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://www.paypal.com/paypalme/mmramadan496)

## License

The MIT License (MIT).
