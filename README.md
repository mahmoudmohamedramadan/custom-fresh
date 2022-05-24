# Custom Fresh

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

```BASH
php artisan fresh:custom users password_resets
```

### Example

![cmd](https://user-images.githubusercontent.com/48416569/169878698-e162d509-0eab-4c89-8ecd-d9fcee3f9f34.png)

## Credits

- [Mahmoud Mohamed Ramadan](https://github.com/mahmoudmohamedramadan)
- [Contributors](https://github.com/mahmoudmohamedramadan/custom-fresh/graphs/contributors)

## Support me

- [PayPal](https://www.paypal.com/paypalme/ramadanpaid)

## License

The MIT License (MIT).
