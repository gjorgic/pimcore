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

namespace Pimcore\Bundle\EcommerceFrameworkBundle\Controller;

use GuzzleHttp\ClientInterface;
use Knp\Component\Pager\PaginatorInterface;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Bundle\AdminBundle\Security\CsrfProtectionHandler;
use Pimcore\Bundle\AdminBundle\Security\User\TokenStorageUserResolver;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractOrder;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractOrderItem;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\CheckoutableInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\Order\Listing;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\Order\Listing\Filter\OrderDateTime;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\Order\Listing\Filter\OrderSearch;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\Order\Listing\Filter\ProductType;
use Pimcore\Bundle\EcommerceFrameworkBundle\OrderManager\V7\OrderManagerInterface;
use Pimcore\Cache;
use Pimcore\Controller\KernelControllerEventInterface;
use Pimcore\Localization\IntlFormatter;
use Pimcore\Localization\LocaleServiceInterface;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\ClassDefinition\Data\ManyToOneRelation;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\OnlineShopOrder;
use Pimcore\Model\DataObject\OnlineShopOrderItem;
use Pimcore\Model\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AdminOrderController
 *
 * @Route("/admin-order")
 *
 * @internal
 */
class AdminOrderController extends AdminController implements KernelControllerEventInterface
{
    /**
     * @var OrderManagerInterface
     */
    protected $orderManager;

    protected $paymentManager;

    /**
     * {@inheritdoc}
     */
    public function onKernelControllerEvent(ControllerEvent $event)
    {
        // set language
        $user = $this->get(TokenStorageUserResolver::class)->getUser();

        if ($user) {
            $this->get('translator')->setLocale($user->getLanguage());
            $event->getRequest()->setLocale($user->getLanguage());
        }

        // enable inherited values
        DataObject::setGetInheritedValues(true);
        Localizedfield::setGetFallbackValues(true);

        $this->orderManager = Factory::getInstance()->getOrderManager();
        $this->paymentManager = Factory::getInstance()->getPaymentManager();
    }

    /**
     * @Route("/list", name="pimcore_ecommerce_backend_admin-order_list", methods={"GET"})
     *
     * @param Request $request
     * @param IntlFormatter $formatter
     * @param PaginatorInterface $paginator
     *
     * @return Response
     */
    public function listAction(Request $request, IntlFormatter $formatter, PaginatorInterface $paginator)
    {
        // create new order list
        /** @var Listing $list */
        $list = $this->orderManager->createOrderList();

        // set list type
        $list->setListType($request->get('type', $list::LIST_TYPE_ORDER));

        // set order state
        $list->setOrderState(AbstractOrder::ORDER_STATE_COMMITTED);

        // add select fields
        $list->addSelectField('order.OrderDate');
        $list->addSelectField('order.orderNumber AS OrderNumber');
        if ($list->getListType() == $list::LIST_TYPE_ORDER) {
            $list->addSelectField('order.totalPrice AS TotalPrice');
        } elseif ($list->getListType() == $list::LIST_TYPE_ORDER_ITEM) {
            $list->addSelectField('orderItem.totalPrice AS TotalPrice');
        }
        $list->addSelectField('count(orderItem.o_id) AS Items');

        // Search
        if ($request->get('q')) {
            $q = htmlentities($request->get('q'));
            $search = $request->get('search');
            switch ($search) {
                case 'productType':
                    $filterProductType = new ProductType();
                    $filterProductType->setTypes([$q]);
                    $list->addFilter($filterProductType);

                    break;

                case 'order':
                default:
                    $filterOrder = new OrderSearch();
                    $filterOrder->setKeyword($q);
                    $list->addFilter($filterOrder);

                    break;
            }
        }

        // add Date Filter
        if ($request->query->has('from') === false && $request->query->has('till') === false) {
            // als default, nehmen wir den ersten des aktuellen monats
            $from = new \DateTime('first day of this month');
            $request->query->set('from', $from->format('Y-m-d'));
        }

        $filterDate = new OrderDateTime();
        if ($request->get('from') || $request->get('till')) {
            $from = $request->get('from') ? new \DateTime($request->get('from')) : null;
            $till = $request->get('till') ? new \DateTime($request->get('till')) : null;
            if ($till) {
                $till->add(new \DateInterval('P1D'));
            }

            if ($from) {
                $filterDate->setFrom($from);
            }
            if ($till) {
                $filterDate->setTill($till);
            }
        }
        $list->addFilter($filterDate);

        if (!empty($request->get('pricingRule'))) {
            $pricingRuleId = $request->get('pricingRule');

            //apply filter on PricingRule(OrderItem)
            $list->joinPricingRule();

            //apply filter on PriceModifications
            $list->joinPriceModifications();
            $list->getQueryBuilder()->andWhere('pricingRule.ruleId = :pricingRuleId OR OrderPriceModifications.pricingRuleId = :pricingRuleId')->setParameter(':pricingRuleId', $pricingRuleId);
        }

        // set default order
        $list->setOrder('order.orderDate desc');

        // Paginate the results of the query
        $paginator = $paginator->paginate(
            $list,
            $request->get('page', 1),
            10
        );

        return $this->render('@PimcoreEcommerceFramework/admin_order/list.html.twig', [
            'paginator' => $paginator,
            'pimcoreUser' => \Pimcore\Tool\Admin::getCurrentUser(),
            'listPricingRule' => new \Pimcore\Bundle\EcommerceFrameworkBundle\PricingManager\Rule\Listing(),
            'defaultCurrency' => Factory::getInstance()->getEnvironment()->getDefaultCurrency(),
            'formatter' => $formatter,
        ]);
    }

    /**
     * @Route("/detail", name="pimcore_ecommerce_backend_admin-order_detail", methods={"GET"})
     *
     * @param Request $request
     * @param ClientInterface $client
     * @param IntlFormatter $formatter
     * @param LocaleServiceInterface $localeService
     *
     * @return Response
     */
    public function detailAction(
        Request $request,
        ClientInterface $client,
        IntlFormatter $formatter,
        LocaleServiceInterface $localeService
    ) {
        $pimcoreSymfonyConfig = $this->getParameter('pimcore.config');

        // init
        $order = OnlineShopOrder::getById((int) $request->get('id'));
        if (!$order) {
            throw $this->createNotFoundException();
        }
        $orderAgent = $this->orderManager->createOrderAgent($order);

        /**
         * @param array $address
         *
         * @return string
         */
        $geoPoint = function (array $address) use ($pimcoreSymfonyConfig, $client) {
            $baseUrl = $pimcoreSymfonyConfig['maps']['geocoding_url_template'];
            $url = str_replace(
                '{q}',
                urlencode(
                    $address[0]
                    . ' ' . $address[1]
                    . ' ' . $address[2]
                    . ' ' . $address[3]
                ),
                $baseUrl
            );

            $json = null;

            try {
                $response = $client->request('GET', $url);
                if ($response->getStatusCode() < 300) {
                    $json = json_decode($response->getBody());
                    if (is_array($json)) {
                        $json = $json[0];
                    }
                }
            } catch (\Exception $e) {
                // noting to do
            }

            return $json;
        };

        // get geo points
        $invoiceAddressCacheKey = 'pimcore_order_invoice_address_' . $order->getId();
        if (!$geoAddressInvoice = Cache::load($invoiceAddressCacheKey)) {
            $geoAddressInvoice = $geoPoint([$order->getCustomerStreet(), $order->getCustomerZip(), $order->getCustomerCity(), $order->getCustomerCountry()]);
            Cache::save(
                $geoAddressInvoice,
                $invoiceAddressCacheKey,
                [ 'object_' . $order->getId() ]
            );
        }

        $geoAddressDelivery = null;
        if ($order->getDeliveryStreet() && $order->getDeliveryZip()) {
            $deliveryAddressCacheKey = 'pimcore_order_delivery_address_' . $order->getId();
            if (!$geoAddressDelivery = Cache::load($deliveryAddressCacheKey)) {
                $geoAddressDelivery = $geoPoint([$order->getDeliveryStreet(), $order->getDeliveryZip(), $order->getDeliveryCity(), $order->getDeliveryCountry()]);
                Cache::save(
                    $geoAddressDelivery,
                    $deliveryAddressCacheKey,
                    [ 'object_' . $order->getId() ]
                );
            }
        }

        // get customer info
        $arrCustomerAccount = [];
        if ($order->getCustomer()) {
            // init
            $customer = $order->getCustomer();

            // register
            $register = \DateTime::createFromFormat('U', (string) $order->getCreationDate());
            $arrCustomerAccount['created'] = $formatter->formatDateTime($register, IntlFormatter::DATE_MEDIUM);

            // mail
            if (method_exists($customer, 'getEMail')) {
                $arrCustomerAccount['email'] = $customer->getEMail();
            }

            // order count
            $addOrderCount = function () use ($customer, &$arrCustomerAccount) {
                $order = new OnlineShopOrder();
                $field = $order->getClass()->getFieldDefinition('customer');
                if ($field instanceof ManyToOneRelation) {
                    $classes = $field->getClasses();
                    if (count($classes) === 1) {
                        /** @var \Pimcore\Model\DataObject\Concrete $class */
                        $class = 'Pimcore\Model\DataObject\\' . reset($classes)['classes'];

                        $orderList = $this->orderManager->createOrderList();
                        $orderList->joinCustomer($class::classId());

                        $orderList->getQueryBuilder()->andWhere('customer.o_id = :customer_oid')->setParameter(':customer_oid', $customer->getId());

                        $arrCustomerAccount['orderCount'] = $orderList->count();
                    }
                }
            };
            $addOrderCount();
        }

        // create timeline
        $arrIcons = [
            'itemChangeAmount' => 'fa fa-pen', 'itemCancel' => 'fa fa-times', 'itemComplaint' => 'fa fa-exclamation-triangle',
        ];

        $arrContext = [
            'itemChangeAmount' => 'secondary', 'itemCancel' => 'danger', 'itemComplaint' => 'warning',
        ];

        $arrTimeline = [];
        $date = new \DateTime();
        foreach ($orderAgent->getFullChangeLog() as $note) {
            $quantity = null;

            // get avatar
            $user = User::getById($note->getUser());
            $avatar = $user ? sprintf('/admin/user/get-image?id=%d', $user->getId()) : null;

            // group events
            $date->setTimestamp($note->getDate());
            $group = $formatter->formatDateTime($date, IntlFormatter::DATE_MEDIUM);

            // load reference
            $reference = Concrete::getById($note->getCid());
            $title = $reference instanceof AbstractOrderItem && $reference->getProduct() instanceof CheckoutableInterface
                ? $reference->getProduct()->getOSName()
                : null
            ;

            if (isset($note->getData()['quantity'])) {
                $quantity = $note->getData()['quantity']['data'];
            } elseif (isset($note->getData()['amount.new'])) {
                $quantity = $note->getData()['amount.new']['data'];
            }

            // add
            $arrTimeline[$group][] = [
                'icon' => $arrIcons[$note->getTitle()] ?? '',
                'context' => $arrContext[$note->getTitle()] ?? 'default',
                'type' => $note->getTitle(),
                'date' => $formatter->formatDateTime($date->setTimestamp($note->getDate()), IntlFormatter::DATETIME_MEDIUM),
                'avatar' => $avatar,
                'user' => $user ? $user->getName() : null,
                'message' => $note->getData()['message']['data'] ?? '',
                'title' => $title ?: $note->getTitle(),
                'quantity' => $quantity,
            ];
        }

        $paymentProviders = $this->paymentManager->getProviderTypes();

        return $this->render('@PimcoreEcommerceFramework/admin_order/detail.html.twig', [
            'pimcoreUser' => \Pimcore\Tool\Admin::getCurrentUser(),
            'orderAgent' => $orderAgent,
            'timeLine' => $arrTimeline,
            'geoAddressInvoice' => $geoAddressInvoice,
            'arrCustomerAccount' => $arrCustomerAccount,
            'geoAddressDelivery' => $geoAddressDelivery,
            'pimcoreSymfonyConfig' => $pimcoreSymfonyConfig,
            'formatter' => $formatter,
            'locale' => $localeService,
            'paymentProviders' => $paymentProviders,
        ]);
    }

    /**
     * @Route("/item-cancel", name="pimcore_ecommerce_backend_admin-order_item-cancel", methods={"GET", "POST"})
     *
     * @param Request $request
     * @param CsrfProtectionHandler $csrfProtection
     *
     * @return Response
     */
    public function itemCancelAction(Request $request, CsrfProtectionHandler $csrfProtection)
    {
        // init
        $orderItem = OnlineShopOrderItem::getById((int) $request->get('id'));
        if (!$orderItem) {
            throw $this->createNotFoundException();
        }
        $order = $orderItem->getOrder();

        if ($request->get('confirmed') && $orderItem->isCancelAble()) {
            $csrfProtection->checkCsrfToken($request);
            // init
            $agent = $this->orderManager->createOrderAgent($order);

            // cancel
            $note = $agent->itemCancel($orderItem);

            // extend log
            $note->addData('message', 'text', $request->get('message'));
            $note->save();

            // redir
            $url = $this->generateUrl('pimcore_ecommerce_backend_admin-order_detail', ['id' => $order->getId()]);

            return $this->redirect($url);
        }

        return $this->render('@PimcoreEcommerceFramework/admin_order/item_cancel.html.twig', [
            'orderItem' => $orderItem,
        ]);
    }

    /**
     * @Route("/item-edit", name="pimcore_ecommerce_backend_admin-order_item-edit", methods={"GET", "POST"})
     *
     * @return Response
     */
    public function itemEditAction(Request $request, CsrfProtectionHandler $csrfProtectionHandler)
    {
        // init
        $orderItem = OnlineShopOrderItem::getById((int) $request->get('id'));
        if (!$orderItem) {
            throw $this->createNotFoundException();
        }
        $order = $orderItem->getOrder();

        if ($request->get('confirmed')) {
            $csrfProtectionHandler->checkCsrfToken($request);

            // change item
            $agent = $this->orderManager->createOrderAgent($order);
            $note = $agent->itemChangeAmount($orderItem, $request->get('quantity'));

            // extend log
            $note->addData('message', 'text', $request->get('message')); // 'text','date','document','asset','object','bool'
            $note->save();

            // redir
            $url = $this->generateUrl('pimcore_ecommerce_backend_admin-order_detail', ['id' => $order->getId()]);

            return $this->redirect($url);
        }

        return $this->render('@PimcoreEcommerceFramework/admin_order/item_edit.html.twig', [
            'orderItem' => $orderItem,
        ]);
    }

    /**
     * @Route("/item-complaint", name="pimcore_ecommerce_backend_admin-order_item-complaint", methods={"GET", "POST"})
     *
     * @return Response
     */
    public function itemComplaintAction(Request $request, CsrfProtectionHandler $csrfProtectionHandler)
    {
        // init
        $orderItem = OnlineShopOrderItem::getById((int) $request->get('id'));
        if (!$orderItem) {
            throw $this->createNotFoundException();
        }
        $order = $orderItem->getOrder();

        if ($request->get('confirmed')) {
            $csrfProtectionHandler->checkCsrfToken($request);

            // change item
            $agent = $this->orderManager->createOrderAgent($order);
            $note = $agent->itemComplaint($orderItem, $request->get('quantity'));

            // extend log
            $note->addData('message', 'text', $request->get('message'));
            $note->save();

            // redir
            $url = $this->generateUrl('pimcore_ecommerce_backend_admin-order_detail', ['id' => $order->getId()]);

            return $this->redirect($url);
        }

        return $this->render('@PimcoreEcommerceFramework/admin_order/item_complaint.html.twig', [
            'orderItem' => $orderItem,
        ]);
    }
}
