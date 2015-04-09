<?php

namespace Account\Wallet;

use \Monolog\Logger;
use \Account\Account;
use \Account\DisabledAccount;
use \Account\SimpleAccountType;
use \Account\AccountFetchException;
use \Apis\FetchHttpException;
use \Apis\FetchException;
use \Apis\Fetch;
use \Openclerk\Currencies\CurrencyFactory;

use \Openclerk\OAuth2\Client\Provider\Coinbase as CoinbaseProvider;

/**
 * Represents an {@link AccountType} that requires some sort of self-updating
 * mechanism with the account data.
 *
 * This is necessary for e.g. OAuth2 updating access_tokens, refresh_tokens
 */
interface SelfUpdatingAccount {
  /**
   * When we have finished with this account, register this callback
   * that will update the account data with the given new data.
   *
   * This is necessary for e.g. OAuth2 updating access_tokens, refresh_tokens
   */
  function registerAccountUpdateCallback($callback);
}

/**
 * Represents an {@link AccountType} that allows fields to <i>instead</i> be
 * populated by doing something with the user.
 */
interface UserInteractionAccount {

  /**
   * Prepare the user agent to redirect, etc.
   * If user interaction is complete, instead returns an array of valid field values.
   *
   * @return either {@code null} (interaction is not yet complete) or an array of valid field values
   * @throws AccountFetchException if something bad happened
   */
  function interaction(Logger $logger);

}

/**
 * Represents the Coinbase exchange wallet.
 */
class Coinbase extends AbstractWallet implements SelfUpdatingAccount, UserInteractionAccount {

  public function getName() {
    return "Coinbase";
  }

  function getCode() {
    return "coinbase";
  }

  function getURL() {
    return "https://www.coinbase.com";
  }

  public function getFields() {
    return array(
      'api_code' => array(
        'title' => "API Code",
        'regexp' => '#.+#',
      ),
      'refresh_token' => array(
        'title' => "Refresh Token",
        'regexp' => '#.+#',
      ),
      'access_token' => array(
        'title' => "Access Token",
        'regexp' => '#.+#',
      ),
      'access_token_expires' => array(
        'title' => "Access Token Expires",
      ),
    );
  }

  var $interaction_balance_callback = null;

  function interactionBalanceCallback($fields, Logger $logger) {
    $this->interaction_balance_callback = $fields;
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

      $this->registerAccountUpdateCallback(array($this, 'interactionBalanceCallback'));

      // fetch balances
      $logger->info("Discovering initial access token");
      $ignored = $this->fetchBalances($account, null, $logger);

      // now return the valid fields
      $account = array_merge($account, $this->interaction_balance_callback);

      // unregister callback
      $this->registerAccountUpdateCallback(null);

      return $account;
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
      // 'scopes'        => ['email', '...', '...'],
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
    $need_refresh = time() >= strtotime($account['access_token_expires']);
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
        'authorization_code' => $account['api_code'],
      ));

    }

    $update_account = array(
      'access_token' => $token->accessToken,
      'access_token_expires' => time() + $token->expires,
      'refresh_token' => $token->refreshToken,
    );
    $access_token = $token->accessToken;

    // get user details
    $user = $provider->getUserDetails($token);
    print_r($user);
    die;

    // TODO

  }

}
