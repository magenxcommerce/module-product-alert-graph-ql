# Changelog

## [2.0.0](https://github.com/magenxcommerce/module-product-alert-graph-ql/compare/v1.1.0...v2.0.0) (2026-10-09)


### ⚠ BREAKING CHANGES

* `ProductInterface.is_price_alert_subscribed` and `is_stock_alert_subscribed` are removed. Use `productAlertStatus`.

### Bug Fixes

* Optimize product alert queries with direct database access ([#8](https://github.com/magenxcommerce/module-product-alert-graph-ql/issues/8)) ([8641d0f](https://github.com/magenxcommerce/module-product-alert-graph-ql/commit/8641d0ffe5149e10d96409398f7f43ea06150e17))

## [1.1.0](https://github.com/magenxcommerce/module-product-alert-graph-ql/compare/v1.0.0...v1.1.0) (2026-08-15)


### Features

* expose alert status, status-changed timestamp, and price diff ([#5](https://github.com/magenxcommerce/module-product-alert-graph-ql/issues/5)) ([8af5110](https://github.com/magenxcommerce/module-product-alert-graph-ql/commit/8af511025b98ba3f71c176cbdd5c0635a619e3fd))

## 1.0.0 (2026-08-11)


### Miscellaneous Chores

* MagenX Commerce Magento 2 module ([86d6a81](https://github.com/magenxcommerce/module-product-alert-graph-ql/commit/86d6a81fc65ad2b9e3c8ab5ea5239c57047cf301))
* Magenxcommerce composer namespace ([eb2c88b](https://github.com/magenxcommerce/module-product-alert-graph-ql/commit/eb2c88bafc460c9dddc6b4e46e28e12b1b638392))
