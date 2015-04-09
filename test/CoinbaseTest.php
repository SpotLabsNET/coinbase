<?php

namespace Account\Wallet\Tests;

use Monolog\Logger;
use Account\AccountType;
use Account\AccountFetchException;
use Account\Tests\AbstractActiveAccountTest;
use Openclerk\Config;
use Openclerk\Currencies\Currency;

/**
 * Tests {@link Coinbase} accounts.
 */
abstract class CoinbaseTest extends AbstractActiveAccountTest {

  public function __construct(AccountType $type) {
    parent::__construct($type);
    Config::merge(array(
      // reduce throttle time for tests
      "accounts_throttle" => 1,
    ));
  }

  /**
   * In openclerk/wallets, extend this to return instances of openclerk/cryptocurrencies
   */
  function loadCurrency($cur) {
    switch ($cur) {
      case "dog":
        return new \Cryptocurrency\Dogecoin();

      default:
        return null;
    }
  }

  function getAccountsJSON() {
    return __DIR__ . "/../accounts.json";
  }

}
