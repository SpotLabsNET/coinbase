<?php

namespace Account\Wallet;

use \Monolog\Logger;
use \Account\Account;
use \Account\DisabledAccount;
use \Account\SimpleAccountType;
use \Account\SelfUpdatingAccount;
use \Account\UserInteractionAccount;
use \Account\AccountFetchException;
use \Apis\FetchHttpException;
use \Apis\FetchException;
use \Apis\Fetch;
use \Openclerk\Currencies\CurrencyFactory;
use \Openclerk\Config;

use \Openclerk\OAuth2\Client\Provider\Coinbase as CoinbaseProvider;

/**
 * Represents the Coinbase exchange wallet.
 */
class Coinbase extends AbstractWallet implements SelfUpdatingAccount, UserInteractionAccount {

  public function getName() {
    return "Coinbase New";
  }

  function getCode() {
    return "coinbase_new";
  }

  function getURL() {
    return "https://www.coinbase.com";
  }

  public function getFields() {
    return array(
      'api_code' => array(
        'title' => "API Code",
        'regexp' => '#.+#',
        'interaction' => true,
      ),
      'refresh_token' => array(
        'title' => "Refresh Token",
        'regexp' => '#.+#',
        'interaction' => true,
      ),
      'access_token' => array(
        'title' => "Access Token",
        'regexp' => '#.+#',
        'interaction' => true,
      ),
      'access_token_expires' => array(
        'title' => "Access Token Expires",
        'interaction' => true,
        'type' => 'datetime',
      ),
    );
  }

  function interaction(Logger $logger) {
    $provider = $this->createProvider();

    if (!isset($_GET['code'])) {
      // if we don't have an authorization code, get one
      $logger->info("Obtaining authorization code");

      $url = $provider->getAuthorizationUrl();
      $logger->info($url);

      // for CSRF attack
      $_SESSION['oauth2state'] = $provider->state;
      header('Location: ' . $url);
      return null;

    } elseif (empty($_GET['state']) || $_GET['state'] !== $_SESSION['oauth2state']) {
      // possible CSRF attack
      $logger->info("Intercepted invalid state");

      unset($_SESSION['oauth2state']);
      throw new AccountFetchException("Invalid state");

    } else {
      // get the initial keys
      $account = array(
        'api_code' => $_GET['code'],
      );

      // fetch balances
      $logger->info("Using authorization_code to load initial access token");
      $provider = $this->createProvider();

      $token = $provider->getAccessToken('authorization_code', array(
        'code' => $account['api_code'],
      ));

      return array(
        'api_code' => $account['api_code'],
        'access_token' => $token->accessToken,
        'access_token_expires' => date('Y-m-d H:i:s', $token->expires),   // Coinbase returns time, not seconds until
        'refresh_token' => $token->refreshToken,
      );
    }
  }

  var $account_update_callback = null;

  function registerAccountUpdateCallback($callback) {
    $this->account_update_callback = $callback;
  }

  public function fetchSupportedCurrencies(CurrencyFactory $factory, Logger $logger) {
    throw new AccountFetchException("Not implemented yet");
    // there is no public API to list supported currencies
    return array('btc', 'nzd');
  }

  public function createProvider() {
    return new CoinbaseProvider(array(
      'clientId' => Config::get('coinbase_client_id'),
      'clientSecret' => Config::get('coinbase_client_secret'),
      'redirectUri' => Config::get('coinbase_redirect_uri'),
      'scopes' => array('user', 'balance'),       // we don't really want 'user' but the oauth2-client requires it
    ));
  }

  /**
   * @return all account balances
   * @throws AccountFetchException if something bad happened
   */
  public function fetchBalances($account, CurrencyFactory $factory, Logger $logger) {

    if (!$this->account_update_callback) {
      throw new AccountFetchException("Need to register an registerAccountUpdateCallback for this account type");
    }

    // we should have an API code by now
    // we'll just always request new access tokens on every request, to reduce errors
    $need_refresh = true; // time() >= strtotime($account['access_token_expires']);
    $update_account = false;

    if ($need_refresh) {
      $logger->info("Refreshing access token");
      $provider = $this->createProvider();

      $grant = new \League\OAuth2\Client\Grant\RefreshToken();
      $token = $provider->getAccessToken($grant, array(
        "refresh_token" => $account['refresh_token'],
      ));

    } else {
      $logger->info("Using authorization_code to load access token");
      $provider = $this->createProvider();

      $token = $provider->getAccessToken('authorization_code', array(
        'code' => $account['api_code'],
      ));

    }

    $update_account = array(
      'access_token' => $token->accessToken,
      'access_token_expires' => date('Y-m-d H:i:s', $token->expires),   // Coinbase returns time, not seconds until
      'refresh_token' => $token->refreshToken,
    );
    $access_token = $token->accessToken;

    // update account details
    $logger->info("Self-updating account");
    call_user_func($this->account_update_callback, $update_account);

    // get balance details
    $logger->info("Fetching balance details");
    $balance = $provider->getBalanceDetails($token);

    $result = array();
    $result[strtolower($balance['currency'])] = array(
      'confirmed' => $balance['amount'],
    );

    return $result;

  }

}
