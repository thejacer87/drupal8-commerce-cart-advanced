<?php

namespace Drupal\commerce_cart_advanced;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Creates and loads carts for anonymous and authenticated users.
 *
 * On top of the default cart provider interface, it defines functions for
 * getting current carts only.
 *
 * @see \Drupal\commerce_cart\CartSessionInterface
 */
interface AdvancedCartProviderInterface extends CartProviderInterface {

  /**
   * Gets the current cart order for the given store and user.
   *
   * @param string $order_type
   *   The order type ID.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store. If empty, the current store is assumed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user. If empty, the current user is assumed.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The cart order, or NULL if none found.
   */
  public function getCurrentCart(
    $order_type,
    StoreInterface $store = NULL,
    AccountInterface $account = NULL
  );

  /**
   * Gets the current cart order ID for the given store and user.
   *
   * @param string $order_type
   *   The order type ID.
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store. If empty, the current store is assumed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user. If empty, the current user is assumed.
   *
   * @return int|null
   *   The cart order ID, or NULL if none found.
   */
  public function getCurrentCartId(
    $order_type,
    StoreInterface $store = NULL,
    AccountInterface $account = NULL
  );

}
