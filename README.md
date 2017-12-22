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


## Configuration Reference
```yaml
trade:
    btc_markets:
        api_key: 'your-api-key-here'
        private_key: 'your-api-private-key-here'

```
