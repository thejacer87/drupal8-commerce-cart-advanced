<?php

namespace Drupal\commerce_cart_advanced\Controller;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
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
   * Constructs a new CartController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    CartProviderInterface $cart_provider
  ) {
    $this->configFactory = $config_factory;
    $this->cartProvider = $cart_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('commerce_cart.cart_provider')
    );
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

    // Build the first cart (current cart).
    // That needs a bit more work since we may have multiple current carts
    // e.g. one per store.
    $first_cart = current($carts);
    $cart_views = $this->getCartViews([$first_cart->id() => $first_cart]);
    unset($carts[key($carts)]);
    $build[$first_cart->id()] = $this->buildCart(
      $first_cart,
      $cart_views[$first_cart->id()],
      $cacheable_metadata
    );

    // If the configuration dictates to display only the current cart, or if we
    // don't have non-current carts, we're done.
    $config = $this->configFactory->getEditable('commerce_cart_advanced.settings');
    $display_non_current_carts = $config->get('display_non_current_carts');
    if (!$display_non_current_carts || !$carts) {
      $this->buildCache($build, $cacheable_metadata);
      return $build;
    }

    // Otherwise, build non-current carts.
    $cart_views = $this->getCartViews(
      $carts,
      'commerce_cart_advanced',
      'commerce_cart_form'
    );

    $non_current_cart_forms = $this->buildNonCurrentCarts(
      $carts,
      $cart_views,
      $cacheable_metadata,
      ['cart--non-current-form']
    );

    $build['non_current_carts'] = [
      '#theme' => 'commerce_cart_advanced_non_current',
      '#carts' => $non_current_cart_forms,
    ];
    $this->buildCache($build, $cacheable_metadata);

    return $build;
  }

  /**
   * Builds the non current cart form render array.
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
   *   The non current carts form render array.
   */
  protected function buildNonCurrentCarts(
    array $carts,
    array $cart_views,
    CacheableMetadata $cacheable_metadata,
    array $classes = []
  ) {
    $carts_build = [];
    foreach ($carts as $cart_id => $cart) {
      $carts_build[$cart_id] = $this->buildCart(
        $cart,
        $cart_views[$cart->id()],
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

}
