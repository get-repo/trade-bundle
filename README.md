# Trade Bundle

## Installation


**Composer**
```bash
composer config repositories.get-repo/trade-bundle git https://github.com/get-repo/trade-bundle
composer require get-repo/trade-bundle
```


**Update your `./app/AppKernel.php`**
```php
$bundles = [
    ...
    new GetRepo\TradeBundle\TradeBundle(),
    ...
];
```
or with
```bash
php -r "file_put_contents('./app/AppKernel.php', str_replace('];', \"    new GetRepo\TradeBundle\TradeBundle(),\n        ];\", file_get_contents('./app/AppKernel.php')));"
```


**Add chart route in `./app/config/routing.yml`**
```php
trade_charts:
    resource: '@TradeBundle/Resources/config/routing.yml'
    prefix: trade
```



## Command Line
```bash
Usage:
  trade:btc [options] [--] <action> [<filter>]

Arguments:
  action                Action name.
  filter                Optional filter.

Options:
      --with-orderbook  Collect data with order book
  -h, --help            Display this help message

Help:
  The trade:btc manage BTC market trades
  
  Show your blance:
    php bin/console trade:btc balance (alias: b)
  Show your funds:
    php bin/console trade:btc funds (alias: f)
  Show the currency prices:
    php bin/console trade:btc ticks (alias: t)
  Show the order book for an instrument:
    php bin/console trade:btc market (alias: m) BTC
    php bin/console trade:btc m XRP,15
  Price alert (infinite loop script):
    php bin/console trade:btc alert (alias: a) XRP,1.5
  Clear the cache:
    php bin/console trade:btc clear-cache (alias: cc)
```



## Configuration Reference
```yaml
trade:
    btc_markets:
        api_key: 'your-api-key-here'
        private_key: 'your-api-private-key-here'

```
