<?php
/**
 * @copyright Copyright (c) 2014 X.commerce, Inc. (http://www.magentocommerce.com)
 */

namespace Magento\Quote\Model;

use Magento\Quote\Model\Quote as QuoteEntity;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Quote\Model\QuoteValidator;
use Magento\Sales\Api\Data\OrderDataBuilder as OrderBuilder;
use Magento\Sales\Api\Data\OrderItemDataBuilder as OrderItemBuilder;
use Magento\Sales\Api\OrderManagementInterface as OrderManagement;
use Magento\Quote\Model\Quote\Address\ToOrder as ToOrderConverter;
use Magento\Quote\Model\Quote\Address\ToOrderAddress as ToOrderAddressConverter;
use Magento\Quote\Model\Quote\Item\ToOrderItem as ToOrderItemConverter;
use Magento\Quote\Model\Quote\Payment\ToOrderPayment as ToOrderPaymentCoverter;
use Magento\Quote\Model\CustomerManagement;

/**
 * Class QuoteManagement
 */
class QuoteManagement
{
    protected $eventManager;
    protected $quoteValidator;
    /**
     * @var OrderBuilder|\Magento\Framework\Api\Builder
     */
    protected $orderBuilder;

    protected $orderManagement;
    protected $customerManagement;

    protected $quoteAddressToOrder;
    protected $quoteAddressToOrderAddress;
    protected $quoteItemToOrderItem;
    protected $quotePaymentToOrderPayment;


    public function __construct(
        EventManager $eventManagement,
        QuoteValidator $quoteValidator,
        OrderBuilder $orderBuilder,
        OrderManagement $orderManagement,
        CustomerManagement $customerManagement,
        ToOrderConverter $quoteAddressToOrder,
        ToOrderAddressConverter $quoteAddressToOrderAddress,
        ToOrderItemConverter $quoteItemToOrderItem,
        ToOrderPaymentCoverter $quotePaymentToOrderPayment
    ) {
        $this->eventManager = $eventManagement;
        $this->quoteValidator = $quoteValidator;
        $this->orderBuilder = $orderBuilder;
        $this->orderManagement = $orderManagement;
        $this->customerManagement = $customerManagement;
        $this->quoteAddressToOrder = $quoteAddressToOrder;
        $this->quoteAddressToOrderAddress = $quoteAddressToOrderAddress;
        $this->quoteItemToOrderItem = $quoteItemToOrderItem;
        $this->quotePaymentToOrderPayment = $quotePaymentToOrderPayment;
    }

    protected function inactivateQuote(QuoteEntity $quote)
    {
        $quote->setIsActive(false);
    }

    protected function deleteNominalItems(QuoteEntity $quote)
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->isNominal()) {
                $item->isDeleted(true);
            }
        }
    }

    public function submitNominalItems(QuoteEntity $quote)
    {
        $this->quoteValidator->validateBeforeSubmit($quote);
        $this->eventManager->dispatch('sales_model_service_quote_submit_nominal_items',
            [
                'quote' => $quote
            ]
        );
        $this->inactivateQuote($quote);
        $this->deleteNominalItems($quote);
    }

    public function submit(QuoteEntity $quote, $orderData = [])
    {
        try {
            $this->submitNominalItems($quote);
        } catch (\Exception $e) {
            throw $e;
        }
        if (!$quote->getAllVisibleItems()) {
            $this->inactivateQuote($quote);
            return;
        }
        return $this->submitOrder($quote, $orderData);
    }

    protected function resolveItems(QuoteEntity $quote)
    {
        $quoteItems = $quote->getAllItems();
        for($i = 0; $i < count($quoteItems) - 1; $i++) {
            for ($j = 0; $i < count($quoteItems) - $i - 1; $j++) {
                if ($quoteItems[$i]->getId() == $quoteItems[$j]->getParentItemId()) {
                    $quote = $quoteItems[$i];
                    $quoteItems[$i] = $quoteItems[$j];
                    $quoteItems[$j] = $quote;
                }
            }
        }
        $orderItems = [];
        foreach ($quoteItems as $quoteItem) {
            $parentItem = null;
            $parentItem = (isset($orderItems[$quoteItem->getParentItemId()])) ?
                $orderItems[$quoteItem->getParentItemId()] : null;
            $orderItems[$quoteItem->getId()] =
                $this->quoteItemToOrderItem->convert($quoteItem, ['parent_item_id' => $parentItem]);
        }
        return array_values($orderItems);
    }

    protected function submitOrder(QuoteEntity $quote, $orderData = [])
    {
        $this->deleteNominalItems($quote);
        $this->quoteValidator->validateBeforeSubmit($quote);
        if (!$quote->getCustomerIsGuest()) {
            $this->customerManagement->populateCustomerInfo($quote);
        }
        $addresses = [];
        if ($quote->isVirtual()) {
            $this->orderBuilder->populate(
                $this->quoteAddressToOrder->convert($quote->getBillingAddress(), $orderData)
            );
        } else {
            $this->orderBuilder->populate(
                $this->quoteAddressToOrder->convert($quote->getShippingAddress(), $orderData)
            );
            $shippingAddress = $this->quoteAddressToOrderAddress->convert(
                $quote->getShippingAddress(), ['address_type' => 'shipping']
            );
            $addresses[] = $shippingAddress;
            $this->orderBuilder->setShippingAddress($shippingAddress);

        }
        $billingAddress = $this->quoteAddressToOrderAddress->convert(
            $quote->getBillingAddress(), ['address_type' => 'billing']
        );
        $addresses[] = $billingAddress;
        $this->orderBuilder->setBillingAddress($billingAddress);
        $this->orderBuilder->setAddresses($addresses);
        $this->orderBuilder->setPayments(
            [$this->quotePaymentToOrderPayment->convert($quote->getPayment())]
        );
        $this->orderBuilder->setItems($this->resolveItems($quote));
        if ($quote->getCustomer()) {
            $this->orderBuilder->setCustomerId($quote->getCustomer()->getId());
        }
        $this->orderBuilder->setQuoteId($quote->getId());
        $order = $this->orderBuilder->create();
        $this->eventManager->dispatch('sales_model_service_quote_submit_before',
            [
                'order' => $order,
                'quote' => $quote
            ]
        );
        try {
            $order = $this->orderManagement->place($order);
            $this->inactivateQuote($quote);
            $this->eventManager->dispatch('sales_model_service_quote_submit_success',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );
        } catch (\Exception $e) {
            $this->eventManager->dispatch('sales_model_service_quote_submit_failure',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );
            throw $e;
        }
        return $order;
    }
}
