## JetFire Dependency Injection Container

Di is a minimalist Dependency Injection Container for PHP inspired from [Dice](https://github.com/Level-2/Dice).

### Installation

Via [composer](https://getcomposer.org)

```bash
$ composer require jetfirephp/di
```

### Basic Usage

```php
// Require composer autoloader
require __DIR__ . '/vendor/autoload.php';

$rules = [
    'account' => [
        'use' => 'Account',
        'rule' => [
            'shared' => true,
        ]
    ],
    'amount' => [
        'use' => 'Amount',
    ],
];

$di = new Di();
$di->registerCollection($rules);

$account1 = $di->get('account'); // instance of Account
$account2 = $di->get('account');
var_dump($account1 === $account2) //true

$amount1 = $di->get('amount');
$amount2 = $di->get('amount');
var_dump($amount1 === $amount2) //false
 
```

### License

The JetFire Di is released under the MIT public license : http://www.opensource.org/licenses/MIT. 