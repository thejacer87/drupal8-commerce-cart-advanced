<?php

namespace Drupal\commerce_cart_advanced;

use Drupal\commerce_cart\CartProvider;
use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_cart\Exception\DuplicateCartException;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Implementation of the advanced cart provider.
 */
class AdvancedCartProvider extends CartProvider implements AdvancedCartProviderInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $databaseConnection;

  /**
   * The loaded current cart data, grouped by uid, then keyed by cart order ID.
   *
   * In contrast to the parent cart provider's `cartData` property, it only
   * stores data for current carts.
   *
   * Each data item is an array with the following keys:
   * - type: The order type.
   * - store_id: The store ID.
   *
   * Example:
   * @code
   * 1 => [
   *   10 => ['type' => 'default', 'store_id' => '1'],
   * ]
   * @endcode
   *
   * @var array
   */
  protected $currentCartData = [];

  /**
   * Constructs a new CartProvider object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_store\CurrentStoreInterface $current_store
   *   The current store.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\commerce_cart\CartSessionInterface $cart_session
   *   The cart session.
   * @param \Drupal\Core\Database\Connection $database_connection
   *   The database connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CurrentStoreInterface $current_store,
    AccountInterface $current_user,
    CartSessionInterface $cart_session,
    DatabaseConnection $database_connection
  ) {
    $this->orderStorage = $entity_type_manager->getStorage('commerce_order');
    $this->currentStore = $current_store;
    $this->currentUser = $current_user;
    $this->cartSession = $cart_session;
    $this->databaseConnection = $database_connection;
  }

  /**
   * {@inheritdoc}
   *
   * We override this function because the parent cart provider blocks creating
   * more than one cart order per type, store and user. We do however want to
   * allow that in the case that the existing cart order(s) per combination is
   * non current (archived).
   */
  public function createCart(
    $order_type,
    StoreInterface $store = NULL,
    AccountInterface $account = NULL
  ) {
    $store = $store ?: $this->currentStore->getStore();
    $account = $account ?: $this->currentUser;
    $uid = $account->id();
    $store_id = $store->id();

    // Don't allow multiple cart orders matching the same criteria.
    if ($this->getCurrentCartId($order_type, $store, $account)) {
      throw new DuplicateCartException(sprintf(
        'A current cart order for type "%s", store "%s" and account "%s" already exists',
        $order_type,
        $store_id,
        $uid
      ));
    }

    // Create the new cart order.
    $cart = $this->orderStorage->create([
      'type' => $order_type,
      'store_id' => $store_id,
      'uid' => $uid,
      'cart' => TRUE,
    ]);
    $cart->save();

    // Store the new cart order id in the anonymous user's session so that it
    // can be retrieved on the next page load.
    if ($account->isAnonymous()) {
      $this->cartSession->addCartId($cart->id());
    }

    // Cart data has already been loaded, add the new cart order to the list.
    // Add it to the current carts list as well; it's a new cart so it must be
    // current.
    if (isset($this->cartData[$uid])) {
      $this->cartData[$uid][$cart->id()] = [
        'type' => $order_type,
        'store_id' => $store_id,
      ];
    }
    if (isset($this->currentCartData[$uid])) {
      $this->currentCartData[$uid][$cart->id()] = [
        'type' => $order_type,
        'store_id' => $store_id,
      ];
    }

    return $cart;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentCart(
    $order_type,
    StoreInterface $store = NULL,
    AccountInterface $account = NULL
  ) {
    $cart_id = $this->getCurrentCartId($order_type, $store, $account);
    if ($cart_id) {
      return $this->orderStorage->load($cart_id);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentCartId(
    $order_type,
    StoreInterface $store = NULL,
    AccountInterface $account = NULL
  ) {
    $cart_data = $this->loadCurrentCartData($account);
    if ($cart_data) {
      $store = $store ?: $this->currentStore->getStore();
      $search = [
        'type' => $order_type,
        'store_id' => $store->id(),
      ];

      return array_search($search, $cart_data);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearCaches() {
    $this->cartData = [];
    $this->currentCartData = [];
  }

  /**
   * Loads the current cart data for the given user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user. If empty, the current user is assumed.
   *
   * @return array
   *   The current cart data.
   */
  protected function loadCurrentCartData(AccountInterface $account = NULL) {
    $account = $account ?: $this->currentUser;
    $uid = $account->id();

    // Check if we already have the cart data available first.
    if (isset($this->currentCartData[$uid])) {
      return $this->currentCartData[$uid];
    }

    if ($account->isAuthenticated()) {
      return $this->loadCurrentCartDataAuthenticatedUser($uid);
    }

    return $this->loadCurrentCartDataAnonymousUser();
  }

  /**
   * Loads the current cart data for the given authenticated user.
   *
   * @param int $uid
   *   The user ID for which to load the cart data.
   *
   * @return array
   *   The cart data.
   */
  protected function loadCurrentCartDataAuthenticatedUser($uid) {
    $query = $this->buildCurrentCartDataQuery($uid);
    $carts = $query->execute();

    $this->currentCartData[$uid] = [];
    foreach ($carts as $cart) {
      $this->currentCartData[$uid][$cart->order_id] = [
        'type' => $cart->type,
        'store_id' => $cart->store_id,
      ];
    }

    return $this->currentCartData[$uid];
  }

  /**
   * Loads the current cart data for the anonymous user of the current session.
   *
   * @return array
   *   The cart data.
   */
  protected function loadCurrentCartDataAnonymousUser() {
    $uid = 0;
    $this->currentCartData[$uid] = [];

    // Get all carts from the user session.
    $cart_ids = $this->cartSession->getCartIds();
    if (!$cart_ids) {
      return [];
    }

    // Getting the cart data and validating the cart IDs received from the
    // session requires loading the entities. This is a performance hit, but
    // it's assumed that these entities would be loaded at one point anyway.
    /** @var \Drupal\commerce_order\Entity\OrderInterface[] $carts */
    $carts = $this->orderStorage->loadMultiple($cart_ids);
    foreach ($carts as $cart) {
      if ($cart->isLocked()) {
        // Skip locked carts, the customer is probably off-site for payment.
        continue;
      }

      $is_not_cart = empty($cart->cart);
      $is_not_draft = $cart->getState()->value !== 'draft';
      if ($cart->getCustomerId() != $uid || $is_not_cart || $is_not_draft) {
        // Avoid loading non-eligible carts on the next page load.
        $this->cartSession->deleteCartId($cart_id);

        // And skip them i.e. do not add them to the current carts array.
        continue;
      }

      $this->currentCartData[$uid][$cart->id()] = [
        'type' => $cart->bundle(),
        'store_id' => $cart->getStoreId(),
      ];
    }

    return $this->cartData[$uid];
  }

  /**
   * Builds the cart data query for carts marked as non current.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return \Drupal\Core\Database\Query\Select
   *   The select query.
   */
  protected function buildCurrentCartDataQuery($uid) {
    /** @var \Drupal\Core\Database\Query\Select $query */
    $query = $this->databaseConnection
      ->select('commerce_order', 'o')
      ->fields('o', ['order_id', 'store_id', 'type'])
      ->condition('o.state', 'draft')
      ->condition('o.cart', TRUE)
      ->condition('o.locked', FALSE)
      ->condition('o.uid', $uid);
    $query->join('commerce_order__field_non_current_cart', 'n', 'o.order_id = n.entity_id');
    $query
      ->condition('n.field_non_current_cart_value', FALSE)
      ->orderBy('o.order_id', 'DESC');

    return $query;
  }

}
