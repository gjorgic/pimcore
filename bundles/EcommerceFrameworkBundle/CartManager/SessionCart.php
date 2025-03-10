<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\EcommerceFrameworkBundle\CartManager;

use Pimcore\Bundle\EcommerceFrameworkBundle\Tools\SessionConfigurator;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBagInterface;

class SessionCart extends AbstractCart implements CartInterface
{
    /**
     * @var SessionCart[]
     */
    protected static $unserializedCarts;

    /**
     * @return string
     */
    protected function getCartItemClassName()
    {
        return SessionCartItem::class;
    }

    /**
     * @return string
     */
    protected function getCartCheckoutDataClassName()
    {
        return SessionCartCheckoutData::class;
    }

    protected static function getSessionBag(): AttributeBagInterface
    {
        /** @var AttributeBagInterface $sessionBag */
        $sessionBag = \Pimcore::getContainer()->get('session')->getBag(SessionConfigurator::ATTRIBUTE_BAG_CART);

        if (empty($sessionBag->get('carts'))) {
            $sessionBag->set('carts', []);
        }

        return $sessionBag;
    }

    public function save()
    {
        $session = static::getSessionBag();

        if (!$this->getId()) {
            $this->setId(uniqid('sesscart_'));
        }

        $carts = $session->get('carts');
        $carts[$this->getId()] = serialize($this);

        $session->set('carts', $carts);
    }

    /**
     * @return void
     *
     * @throws \Exception if the cart is not yet saved.
     */
    public function delete()
    {
        $session = static::getSessionBag();

        if (!$this->getId()) {
            throw new \Exception('Cart saved not yet.');
        }

        $this->clear();

        $carts = $session->get('carts');
        unset($carts[$this->getId()]);

        $session->set('carts', $carts);
    }

    /**
     * @param callable $value_compare_func
     *
     * @return $this
     */
    public function sortItems(callable $value_compare_func)
    {
        if (is_array($this->items)) {
            uasort($this->items, $value_compare_func);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function modified()
    {
        return parent::modified();
    }

    /**
     * @param int $id
     *
     * @return SessionCart|null
     */
    public static function getById($id)
    {
        $carts = static::getAllCartsForUser(-1);

        return $carts[$id] ?? null;
    }

    /**
     * @static
     *
     * @param int $userId
     *
     * @return SessionCart[]
     */
    public static function getAllCartsForUser($userId)
    {
        if (null === static::$unserializedCarts) {
            static::$unserializedCarts = [];

            foreach (static::getSessionBag()->get('carts') as $serializedCart) {
                $cart = unserialize($serializedCart);
                static::$unserializedCarts[$cart->getId()] = $cart;
            }
        }

        return static::$unserializedCarts;
    }

    /**
     * @return array
     *
     * @internal
     */
    public function __sleep()
    {
        $vars = parent::__sleep();

        $blockedVars = ['creationDate', 'modificationDate', 'priceCalculator'];

        $finalVars = [];
        foreach ($vars as $key) {
            if (!in_array($key, $blockedVars)) {
                $finalVars[] = $key;
            }
        }

        return $finalVars;
    }

    /**
     * modified flag needs to be set
     *
     * @internal
     */
    public function __wakeup()
    {
        $timestampBackup = $this->getModificationDate();

        // set current cart
        foreach ($this->getItems() as $item) {
            $item->setCart($this);

            if ($item->getSubItems()) {
                foreach ($item->getSubItems() as $subItem) {
                    $subItem->setCart($this);
                }
            }
        }
        $this->modified();

        $this->setModificationDate($timestampBackup);
    }
}
