<?php

namespace Drupal\commerce_cart_advanced\Controller;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_cart\CartSessionInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the cart page that provides advanced cart functionality.
 */
class CartController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cart provider.
   *
   * @var \Drupal\commerce_cart\CartProviderInterface
   */
  protected $cartProvider;

  /**
   * The cart session.
   *
   * @var \Drupal\commerce_cart\CartSessionInterface
   */
  protected $cartSession;

  /**
   * Constructs a new CartController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   * @param \Drupal\commerce_cart\CartSessionInterface $cart_session
   *   The cart session.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CartProviderInterface $cart_provider,
    CartSessionInterface $cart_session
  ) {
    $this->configFactory = $config_factory;
    $this->cartProvider = $cart_provider;
    $this->cartSession = $cart_session;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('commerce_cart.cart_provider'),
      $container->get('commerce_cart.cart_session')
    );
  }

  /**
   * Checks access.
   *
   * Confirms that the user access to the carts.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function checkAccess(
    RouteMatchInterface $route_match,
    AccountInterface $account
  ) {
    /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
    $cart = $route_match->getParameter('cart');

    // The user can only view their carts.
    $is_owner = $account->id() == $cart->getCustomerId();

    // Additionally, if the user is anonymous the IDs should still be matching
    // but they would always be 0 making it possible to view other anonymous
    // user's carts. Check that the cart is available in the current user's
    // session to verify ownership.
    if (!$account->isAuthenticated()) {
      $is_owner = $is_owner && $this->cartSession->hasCartId($cart->id());
    }

    // Make sure that we are viewing a cart and not a placed order.
    $is_cart = $cart->getState()->value === 'draft' && $cart->cart;

    // At last, make sure that the cart is not locked. Carts may get locked
    // during the checkout process such as when going off-site for making a
    // payment.
    $is_not_locked = !$cart->isLocked();

    $access = AccessResult::allowedIf($is_owner)
      ->andIf(AccessResult::allowedIf($is_cart))
      ->andIf(AccessResult::allowedIf($is_not_locked))
      ->addCacheableDependency($cart);

    return $access;
  }

  /**
   * Outputs a cart view for the passed in cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The selected cart.
   *
   * @return array
   *   A render array.
   */
  public function singleCartPage(OrderInterface $cart) {
    $build = [];
    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheContexts(['user', 'session']);

    $cart_id = $cart->id();
    $cart_views = $this->getCartViews([$cart_id => $cart]);
    $build[$cart_id] = [
      '#prefix' => '<div class="cart cart-form">',
      '#suffix' => '</div>',
      '#type' => 'view',
      '#name' => $cart_views[$cart_id],
      '#arguments' => [$cart_id],
      '#embed' => TRUE,
    ];
    $cacheable_metadata->addCacheableDependency($cart);

    $build['#cache'] = [
      'contexts' => $cacheable_metadata->getCacheContexts(),
      'tags' => $cacheable_metadata->getCacheTags(),
      'max-age' => $cacheable_metadata->getCacheMaxAge(),
    ];

    return $build;
  }

  /**
   * Outputs a list of current and non-current carts for the current user.
   *
   * @return array
   *   A render array.
   */
  public function cartPage() {
    $build = [];
    $cacheable_metadata = new CacheableMetadata();
    $cacheable_metadata->addCacheContexts(['user', 'session']);

    // Get all non-empty carts for the user.
    $carts = $this->cartProvider->getCarts();
    $carts = array_filter(
      $carts, function ($cart) {
        /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
        return $cart->hasItems();
      }
    );

    // Display an empty cart if we have no cart available.
    if (!$carts) {
      $this->buildEmptyCart($build);
      $this->buildCache($build, $cacheable_metadata);
      return $build;
    }

    // Split carts into current and non-current carts. The existing $cart array
    // will hold the non-current carts afterwards.
    $current_carts = $this->splitCarts($carts);

    // Build the current carts (first cart per store).
    $build += $this->buildCurrentCarts($current_carts, $cacheable_metadata);

    // If we don't have non-current carts, or if the configuration dictates to
    // display only the current carts, we're done.
    if (!$this->displayNonCurrentCarts($carts)) {
      $this->buildCache($build, $cacheable_metadata);
      return $build;
    }

    // Otherwise, build non-current carts.
    $build += $this->buildNonCurrentCarts($carts, $cacheable_metadata);

    // Add cache data.
    $this->buildCache($build, $cacheable_metadata);

    return $build;
  }

  /**
   * Splits the array of all carts into current and non-current carts.
   *
   * Current carts will be returned in an array, while only non-current carts
   * will remain in the given carts array that is passed by reference.
   *
   * One cart per store is considered current (the first encountered); all other
   * carts are considered as non-current.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   The carts to split.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]
   *   An array with the current carts, keyed by their IDs.
   */
  protected function splitCarts(&$carts) {
    $current_carts = [];
    $store_carts = [];

    // Take out one cart per store from the given array.
    foreach ($carts as $cart_id => $cart) {
      $store_id = $cart->getStoreId();
      if (isset($store_carts[$store_id])) {
        continue;
      }

      $store_carts[$store_id] = $cart;
      unset($carts[$cart_id]);
    }

    // We need the result array to be keyed by the cart IDs.
    foreach ($store_carts as $cart) {
      $current_carts[$cart->id()] = $cart;
    }

    return $current_carts;
  }

  /**
   * Builds the current cart form render array.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   A list of cart orders.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *
   * @return array
   *   The current carts form render array.
   */
  protected function buildCurrentCarts(
    array $carts,
    CacheableMetadata $cacheable_metadata
  ) {
    $cart_views = $this->getCartViews($carts);

    $current_cart_forms = $this->buildCarts(
      $carts,
      $cart_views,
      $cacheable_metadata,
      ['cart--current-form']
    );

    return [
      'current_carts' => [
        '#theme' => 'commerce_cart_advanced_current',
        '#carts' => $current_cart_forms,
      ]
    ];
  }

  /**
   * Builds the non current cart form render array.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   A list of cart orders.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *
   * @return array
   *   The non current carts form render array.
   */
  protected function buildNonCurrentCarts(
    array $carts,
    CacheableMetadata $cacheable_metadata
  ) {
    $cart_views = $this->getCartViews(
      $carts,
      'commerce_cart_advanced',
      'commerce_cart_form'
    );

    $non_current_cart_forms = $this->buildCarts(
      $carts,
      $cart_views,
      $cacheable_metadata,
      ['cart--non-current-form']
    );

    return [
      'non_current_carts' => [
        '#theme' => 'commerce_cart_advanced_non_current',
        '#carts' => $non_current_cart_forms,
      ]
    ];
  }

  /**
   * Builds the render array for the given carts.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   A list of cart orders.
   * @param array $cart_views
   *   An array of view ids keyed by cart order ID.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *   The cacheable metadata.
   * @param array $classes
   *   Optional array of classes to add to the cart form.
   *
   * @return array
   *   The carts form render array.
   */
  protected function buildCarts(
    array $carts,
    array $cart_views,
    CacheableMetadata $cacheable_metadata,
    array $classes = []
  ) {
    $carts_build = [];
    foreach ($carts as $cart_id => $cart) {
      $carts_build[$cart_id] = $this->buildCart(
        $cart,
        $cart_views[$cart_id],
        $cacheable_metadata,
        $classes
      );
    }

    return $carts_build;
  }

  /**
   * Builds a cart form render array.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   A cart order.
   * @param string $cart_view
   *   The view id used to render the cart.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *   The cacheable metadata.
   * @param array $classes
   *   Optional array of classes to add to the cart form.
   *
   * @return array
   *   The cart form render array.
   */
  protected function buildCart(
    OrderInterface $cart,
    $cart_view,
    CacheableMetadata $cacheable_metadata,
    array $classes = []
  ) {
    $cart_build = [
      '#prefix' => '<div class="cart cart-form ' . implode(' ', $classes) . '">',
      '#suffix' => '</div>',
      '#type' => 'view',
      '#name' => $cart_view,
      '#arguments' => [$cart->id()],
      '#embed' => TRUE,
    ];
    $cacheable_metadata->addCacheableDependency($cart);

    return $cart_build;
  }

  /**
   * Adds the empty cart page to the build array.
   *
   * @param array $build
   *   The render array.
   */
  protected function buildEmptyCart(array &$build) {
    $build['empty'] = [
      '#theme' => 'commerce_cart_empty_page',
    ];
  }

  /**
   * Adds cache data to the build.
   *
   * @param array $build
   *   The render array.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata
   *   The cacheable metadata.
   */
  protected function buildCache(array &$build, CacheableMetadata $cacheable_metadata) {
    $build['#cache'] = [
      'contexts' => $cacheable_metadata->getCacheContexts(),
      'tags' => $cacheable_metadata->getCacheTags(),
      'max-age' => $cacheable_metadata->getCacheMaxAge(),
    ];
  }

  /**
   * Gets the cart views for each cart.
   *
   * The views are defined based on the order type settings.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   The cart orders.
   * @param $module
   *   The module providing the third party setting defining the view that
   *   should be used per order type.
   * @param $default_view
   *   The view that should be used by default if no third party setting is
   *   set.
   *
   * @return array
   *   An array of view ids keyed by cart order ID.
   */
  protected function getCartViews(
    array $carts,
    $module = 'commerce_cart',
    $default_view = 'commerce_cart_form'
  ) {
    // Get all order types for the given carts.
    $order_type_ids = array_map(
      function ($cart) {
        /** @var \Drupal\commerce_order\Entity\OrderInterface $cart */
        return $cart->bundle();
      },
      $carts
    );
    $order_types = $this->entityTypeManager()
      ->getStorage('commerce_order_type')
      ->loadMultiple(array_unique($order_type_ids));

    // Get the views.
    $cart_views = [];
    foreach ($order_type_ids as $cart_id => $order_type_id) {
      /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
      $order_type = $order_types[$order_type_id];
      $cart_views[$cart_id] = $order_type->getThirdPartySetting(
        $module,
        'cart_form_view',
        $default_view
      );
    }

    return $cart_views;
  }

  /**
   * Checks whether non-current carts are available and should be displayed.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface[] $carts
   *   The non-current carts.
   *
   * @return bool
   *   Whether the non-current carts should be displayed or not.
   */
  protected function displayNonCurrentCarts($carts) {
    if (!$carts) {
      return FALSE;
    }

    $config = $this->configFactory->get('commerce_cart_advanced.settings');
    $display_non_current_carts = $config->get('display_non_current_carts');
    if (!$display_non_current_carts) {
      return FALSE;
    }

    return TRUE;
  }

}
